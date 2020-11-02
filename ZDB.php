<?php
/****************************************************************************************
* MySQL Database library by ZeusAFK Technologies

Changelog:
* Version 0.2b | 2019-11-28
	- If not params are specified then mysqli query is used instead of mysqli prepare.
	- Removed fetch param.
	- Query method now saves current query string into a private variable with getter 
	  method, helpful for implementing an error handler.
****************************************************************************************/

namespace ZeusAFK;

class Database{

	private static $instance;

	private $connection;
	private $results;
	private $handlers;
	private $error;
	private $on_error;
	private $has_errors;
	private $charset;
	private $query;

	public static function getInstance(){
		if(!self::$instance instanceof self){
			self::$instance = new self;
		}
		return self::$instance;
	}

	function __construct(){
		$this->results = array();
		$this->handlers = array();
		$this->has_errors = false;
		$this->charset = 'utf8';
		$this->on_error = function($type, $description){
			return false;
		};

		$this->RegisterHandler('FETCH_ROWS_HANDLER', function($results){
			$row = array();
			foreach($results as $key => $value) $row[$key] = $value;
			$this->AppendResults($row);
		});

		$this->RegisterHandler('FETCH_ROW_HANDLER', function($results){
			$row = array();
			foreach($results as $key => $value) $row[$key] = $value;
			$this->SetResults($row);
		});

		$this->RegisterHandler('FETCH_FIELD_HANDLER', function($results){
			foreach($results as $key => $value) $this->SetResults($value);
		});

		$this->RegisterHandler('FETCH_UTF8_ROWS_HANDLER', function($results){
			$row = array();
			foreach($results as $key => $value) $row[$key] = utf8_encode($value);
			$this->AppendResults($row);
		});

		$this->RegisterHandler('FETCH_UTF8_ROW_HANDLER', function($results){
			$row = array();
			foreach($results as $key => $value) $row[$key] = utf8_encode($value);
			$this->SetResults($row);
		});

		$this->RegisterHandler('FETCH_UTF8_FIELD_HANDLER', function($results){
			foreach($results as $key => $value) $this->SetResults(utf8_encode($value));
		});

		$this->RegisterHandler('ROWS_HANDLER', $this->GetHandler('FETCH_ROWS_HANDLER'));
		$this->RegisterHandler('ROW_HANDLER', $this->GetHandler('FETCH_ROW_HANDLER'));
		$this->RegisterHandler('FIELD_HANDLER', $this->GetHandler('FETCH_FIELD_HANDLER'));
		$this->RegisterHandler('UTF8_ROWS_HANDLER', $this->GetHandler('FETCH_UTF8_ROWS_HANDLER'));
		$this->RegisterHandler('UTF8_ROW_HANDLER', $this->GetHandler('FETCH_UTF8_ROW_HANDLER'));
		$this->RegisterHandler('UTF8_FIELD_HANDLER', $this->GetHandler('FETCH_UTF8_FIELD_HANDLER'));

		$this->RegisterHandler('ROWS', $this->GetHandler('FETCH_ROWS_HANDLER'));
		$this->RegisterHandler('ROW', $this->GetHandler('FETCH_ROW_HANDLER'));
		$this->RegisterHandler('FIELD', $this->GetHandler('FETCH_FIELD_HANDLER'));
		$this->RegisterHandler('UTF8_ROWS', $this->GetHandler('FETCH_UTF8_ROWS_HANDLER'));
		$this->RegisterHandler('UTF8_ROW', $this->GetHandler('FETCH_UTF8_ROW_HANDLER'));
		$this->RegisterHandler('UTF8_FIELD', $this->GetHandler('FETCH_UTF8_FIELD_HANDLER'));
	}

	public function CreateConnection($host, $user, $password, $database, $port = 3306){
		@$this->connection = new \mysqli($host, $user, $password, $database, $port);

		if($this->connection->connect_errno){
			$this->has_errors = true;
			$this->error = "Fallo al contenctar a MySQL: (" . $this->connection->connect_errno . ") " . $this->connection->connect_error;
			$error_handler = $this->on_error;
			$error_handler && $error_handler('connection_creation', $this->error);
		}else{
			$this->connection->set_charset($this->charset);
		}

		return $this;
	}

	public function SetConnection($connection){
		$this->connection = $connection;
		return $this;
	}

	public function GetConnection(){
		return $this->connection;
	}

	public function GetQuery(){
		return $this->query;
	}

	function RegisterHandler($name, $function){
		$this->handlers[$name] = $function;
		return $this;
	}

	function GetHandler($name){
		return $this->handlers[$name];
	}

	function GetResults(){
		return $this->results;
	}

	function Get(){
		return $this->GetResults();
	}

	function SetResults($results){
		$this->results = $results;
		return $this;
	}

	function AppendResults($results){
		$this->results[] = $results;
		return $this;
	}

	function ClearResults(){
		$this->results = array();
		$this->has_errors = false;
		return $this;
	}

	function SetErrorHandler($on_error){
		$this->on_error = $on_error;
		return $this;
	}

	function Success(){
		return !$this->has_errors;
	}

	function GetError(){
		return $this->error;
	}

	public function Query($query, $types = false, $params = false, $results = false, $callback = false, $connection = false){
		$this->query = $query;
		$this->ClearResults();

		if(!$connection){
			$connection = $this->connection;
		}

		if(is_array($types)){
			$types_array = $types;
			foreach($types_array as $key => $value){
				switch(strtolower($key)){
					case 'types': case 't': {
						$types = $value;
					} break;
					case 'params': case 'p': {
						$params = $value;
					} break;
					case 'results': case 'r': {
						$results = $value;
					} break;
					case 'handler': case 'h': {
						$callback = $value;
					} break;
					case 'connection': case 'c': {
						$connection = $value;
					} break;
				}
			}
		}

		if(is_string($callback) && isset($this->handlers[$callback])){
			$callback = $this->GetHandler($callback);
		}

		$use_prepared = $types && $params;

		if($use_prepared){
			$new_prepared_statement = true;
		
			if($query instanceof \mysqli_stmt){
				$stmt = $query;
				$new_prepared_statement = false;
			}else if(!($stmt = $connection->prepare($query))){
				$this->has_errors = true;
				$this->error = $connection->errno.': '.$connection->error;
				$error_handler = $this->on_error;
				$error_handler && $error_handler('statement_prepare_error', $this->error);
				return $this;
			}

			if(!is_array($params)){
				$params = array($params);
			}

			$referencedParams = array();
			foreach($params as $k => $param){
				$referencesParams[$k] = &$params[$k];
			}
			
			if(sizeof($params) > 0){
				call_user_func_array(array($stmt, "bind_param"), array_merge(array($types), $referencesParams));
			}

			$execute_result = $stmt->execute();
		}else{
			$execute_result = $connection->query($query);
		}
		
		if(!$execute_result){
			$this->has_errors = true;
			$this->error = $connection->errno.': '.$connection->error;
			$error_handler = $this->on_error;
			$error_handler && $error_handler('execute_error', $this->error);
			return $this;
		}
		
		if(!$results){
			$result = $use_prepared ? $stmt->result_metadata() : $execute_result;
			if ($result instanceof \mysqli_result) {
				$info_fields = $result->fetch_fields();
				$results = array();
				foreach ($info_fields as $field){
					$count = 0;
					$field_name = $field->name;
					while(in_array($field_name, $results)){
						$count++;
						$field_name = $field->name.$count;
					}
					$results[] = $field->name.($count > 0 ? $count : '');
				}
			}
		}

		if($use_prepared){
			if($results){
				$formattedResults = array();
				$valuesContainer = array();
				foreach($results as $k => $value){
					$valuesContainer[$k] = null;
					$formattedResults[$value] = &$valuesContainer[$k];
				}
				
				if(sizeof($results) > 0){
					call_user_func_array(array($stmt, "bind_result"), $formattedResults);
				}
			}
			
			if(function_exists('mysqli_fetch_all')){
				do {
					$stmt->store_result();
					$rows = array();
					$row = array();

					if(!$callback){
						if($stmt->num_rows == 1 && sizeof($results) == 1)
							$callback = $this->GetHandler('FETCH_FIELD_HANDLER');
						else if($stmt->num_rows == 1 && sizeof($results) > 1)
							$callback = $this->GetHandler('FETCH_ROW_HANDLER');
						else
							$callback = $this->GetHandler('FETCH_ROWS_HANDLER');
					}

					while($stmt->fetch()){
						if($callback) $callback($formattedResults);
					}
				} while ($stmt->more_results() && $stmt->next_result());
			}else{
				$stmt->store_result();
				$rows = array();
				$row = array();

				if(!$callback){
					if($stmt->num_rows == 1 && sizeof($results) == 1)
						$callback = $this->GetHandler('FETCH_FIELD_HANDLER');
					else if($stmt->num_rows == 1 && sizeof($results) > 1)
						$callback = $this->GetHandler('FETCH_ROW_HANDLER');
					else
						$callback = $this->GetHandler('FETCH_ROWS_HANDLER');
				}

				while($stmt->fetch()){
					if($callback) $callback($formattedResults);
				}
				$stmt->free_result();
				if($new_prepared_statement){
					$stmt->close();
				}
				while ($connection->more_results()){
					$connection->next_result();
					$result = $connection->use_result();
					if ($result instanceof \mysqli_result) {
						$result->free();
					}
				}
			}
		}else{
			if($result instanceof \mysqli_result){
				if(!$callback){
					if($execute_result->num_rows == 1 && sizeof($results) == 1)
						$callback = $this->GetHandler('FETCH_FIELD_HANDLER');
					else if($execute_result->num_rows == 1 && sizeof($results) > 1)
						$callback = $this->GetHandler('FETCH_ROW_HANDLER');
					else
						$callback = $this->GetHandler('FETCH_ROWS_HANDLER');
				}

				while($row = $execute_result->fetch_row()){
					$row_assoc = array();
					foreach($row as $key => $value) $row_assoc[$results[$key]] = $value;
					if($callback) $callback($row_assoc);
				}

				while ($connection->more_results()){
					$connection->next_result();
					$result = $connection->use_result();
					if ($result instanceof \mysqli_result) {
						$result->free();
					}
				}
			}
		}
		
		return $this;
	}
}
