<?php
class Database {
	private $database;

    function connect($config)
    {
        $this->database = ADONewConnection($config['database_type']);
        if (!$this->database)
            return false;

        $result = $this->database->PConnect(
            $config['database_host'],
            $config['database_user'],
            $config['database_password'],
            $config['database_database']
        );

        if (!$result)
            return false;

        return true;
	}

	function query($query_str, $value_array)
	{
		if (!$this->database || !$this->database->IsConnected())
			die('Database connection not available. Exiting.');

		$query = $this->database->Prepare($query_str);

		return $this->database->Execute($query, $value_array);
	}

    function insert_query($query, $params)
	{
		$result = $this->query($query, $params);

		if ($result !== false)
			return $this->database->Insert_ID();

		return false;
	}

    function get_last_error()
    {
        return $this->database->errorMsg();
    }
}
?>
