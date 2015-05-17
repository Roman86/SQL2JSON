<?php
/**
 * Created by: Roman Kozodoy
 * Date: 20.04.14
 * Time: 23:32
 */

class TArrayDataSet{

	protected $fields, $records;
	protected $structLevels;

	public function __construct(){
	}

	/**
	 * Makes data array of mysqli result
	 * @param mysqli_result $res
	 * @return array
	 */
	public static function dataFromMySqlResource(mysqli_result $res){
		$fetchedFields = $res->fetch_fields();
		// поля
		$fields = array();
		foreach($fetchedFields as $f){
			array_push($fields, $f->name);
		}
		// записи
		$records = array();
		while (($row = $res->fetch_row())){
			array_push($records, $row);
		}
		return array("fields" => $fields, "records" => $records);
	}

	/**
	 * @param resource $res
	 * @return array
	 */
	public static function dataFromIBaseResource($res){
		$fNum = ibase_num_fields($res);
		// поля
		$fields = array();
		for($i = 0; $i < $fNum; $i++){
			$info = ibase_field_info($res, $i);
			array_push($fields, $info['alias']);
		}
		// записи
		$records = array();
		while (($row = ibase_fetch_row($res))){
			array_push($records, $row);
		}
		return array("fields" => $fields, "records" => $records);
	}

	/**
	 * @param Array $data array with "fields" and "records" keys (arrays) inside
	 * @throws InvalidArgumentException, UnexpectedValueException
	 */
	public function setData(Array &$data){
		if (gettype($data) != 'array')
			throw new InvalidArgumentException("data should be of array type");

		if (gettype($data['fields']) != 'array')
			throw new UnexpectedValueException('data["fields"] should be of array type');
		if (gettype($data['records']) != 'array')
			throw new UnexpectedValueException('data["records"] should be of array type');

		$this->fields = &$data['fields'];
		foreach($this->fields as &$fName){
			$fName = strtolower($fName);
		}
		$this->records = &$data['records'];
	}

	/**
	 * @throws LogicException
	 */
	protected function assertData(){
		if (!isset($this->records) || !isset($this->fields))
			throw new LogicException("data must be set before use!");
	}

	/**
	 * @param string $fieldName
	 * @return int|string
	 * @throws Exception
	 */
	protected function fieldIndex($fieldName){
		$this->assertData();
		foreach($this->fields as $key => $fName)
			if (strtolower($fieldName) == strtolower($fName))
				return $key;
		throw new Exception("field '$fieldName' not found (was not described) in data");
	}

	/**
	 * @param Array $filter
	 * @param int $structLevel
	 * @param array $recordCallbacks
	 * @return array
	 */
	protected function _toJson(Array &$filter, $structLevel, Array $recordCallbacks=null){
		$struct = $this->structLevels[$structLevel];

		$groupFieldIndex = $struct['groupField'];
		$stringKey = $struct['stringKey'];

		// creating resulting array
		$result = array();

		// gathering all possible values of groupField in filtered dataset
		$values = array();
		foreach($this->records as $key => $row){
			if (!$filter[$key])
				continue;
			if (is_null($row[$groupFieldIndex]))
				continue;
			array_push($values, $row[$groupFieldIndex]);
		}
		// making unique values array
		$values = array_keys(array_count_values($values));

		foreach($values as $groupFieldValue){
			// отфильтровать по groupFieldValue, заполнить поля и уйти с новым фильтром в рекурсию
			$jRow = array();

			$newFilter = $filter; // копируем фильтр, чтобы дополнительно отфильтровать его для передачи в рекурсию
			$fieldsWasSet = false; // поля задаём только один раз, для этого используем флажок
			foreach($this->records as $key => $row){
				if (!$newFilter[$key])
					continue; // отфильтрована ранее

				// новая запись должна быть отфильтрована если значение поля groupIndexField отличается от groupIndexValue
				$neededRecord = ($row[$groupFieldIndex] == $groupFieldValue);
				$newFilter[$key] = $neededRecord;
				if ($neededRecord){
					if (!$fieldsWasSet){
						// копируем значения определённых структурой полей
						foreach($struct['fields'] as $fIndex){
							$fName = $this->fields[$fIndex];
							$val = $row[$fIndex];
							if (is_array($recordCallbacks)){
								$callbackKey = $struct['name'].'.'.$fName;
								if (array_key_exists($callbackKey, $recordCallbacks) && is_callable($recordCallbacks[$callbackKey])){
									$val = call_user_func($recordCallbacks[$callbackKey], $val);
								}
							}
							$jRow[$fName] = $val;
						}
						$fieldsWasSet = true; // больше копировать значения не будем
					}
				}
			}
			if ($structLevel+1 < count($this->structLevels)){
				$nextStructLevel = $structLevel+1;
				$jRow[$this->structLevels[$nextStructLevel]['name']] = $this->_toJson($newFilter, $nextStructLevel, $recordCallbacks);
			}
			if ($stringKey)
				$result[$groupFieldValue] = $jRow;
			else
				array_push($result, $jRow);
		}

		return $result;
	}

	/**
	 * @param String $struct структурирующая строка<br>
	 * <u>Описание формата:</u><br>
	 * <массив><br>
	 * <i>массив:</i><br>
	 * <имя массива>:<группирующее поле>[*](<поля записи>)[/<массив>]<br>
	 * <i>поля записи:</i><br>
	 * <имя поля 1>[,<имя поля 2>[,...]]<br>
	 * Если после группирующего поля стоит символ "*", то в качестве индекса массива вместо порядкового номера будет использоваться значение этого поля
	 * <u>Как это работает</u>
	 * Массив создаёт записи для каждого уникального значения группирующего поля.
	 * Для полей каждой записи выбирается первая строка отфильтрованного набора данных.
	 * Дерево растёт через группирующие поля<br>
	 * Таким образом, чтобы получить все записи в одном массиве нужно сгруппировать по полю, имеющему уникальное значение для каждой записи<br>
	 * <u>Пример:</u><br>
	 * T1:F1a(F1a,F1b)/T2*:F2a(F2a)/T3:F3a(F3a)<br>
	 * @param Boolean $skipRoot если задано, то корневой элемент (верхнего уровня) будет пропущен, т.е. вернётся сразу
	 * массив записей
	 * @param Array $recordCallbacks assoc. array: key - строка "levelName.fieldName" (levelName без пути, просто имя; fieldName - не groupField!), value - callback($val), должен вернуть новое значение
	 * @return mixed
	 * @throws LogicException, InvalidArgumentException
	 */
	public function toJson($struct, $skipRoot, Array $recordCallbacks=null){
		$this->assertData();

		$re = '@([^\s()/:]+):([^\s()/:]+)\(([^()/:]+)\)@i';

		$structLeft = str_replace(' ', '', $struct);
		$this->structLevels = array();
		while(preg_match($re, $structLeft, $matches)){
			list($match, $name, $groupField, $strFields) = $matches;

			if (strstr($groupField, '*')){
				$groupField = str_replace('*', '', $groupField);
				$stringKey = true;
			}else{
				$stringKey = false;
			}
			$groupField = str_replace('*', '', $groupField);
			$fieldNames = explode(',', strtolower($strFields));
			$fields = array();
			foreach($fieldNames as $fName){
				array_push($fields, $this->fieldIndex($fName));
			}
			array_push($this->structLevels, array("name" => $name, "groupField" => $this->fieldIndex($groupField), "fields" => $fields, "stringKey" => $stringKey));
			$structLeft = str_replace($match, '', $structLeft);
		}
		// slashes can remain, replace them
		if (strlen(str_replace('/', '', $structLeft)) > 0)
			throw new InvalidArgumentException("seems like struct has invalid format: '$struct'");

//		print_r($this->structLevels);
		$filter = array();
		$cnt = count($this->records);
		for ($i = 0; $i < $cnt; $i++)
			$filter[$i] = true;

		if (!$skipRoot)
			return array($this->structLevels[0]['name'] => $this->_toJson($filter, 0, $recordCallbacks));
		else
			return $this->_toJson($filter, 0, $recordCallbacks);
	}
}