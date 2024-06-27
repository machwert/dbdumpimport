<?php

class Database
{
    private $connection;

    public function __construct($config)
    {
        $this->connection = new mysqli($config['db_host'], $config['db_user'], $config['db_password'], $config['db_name']);
        if ($this->connection->connect_error) {
            die("Connection failed: " . $this->connection->connect_error);
        }
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function query($sql)
    {
        $result = $this->connection->query($sql);
        if ($result === false) {
            die('Error executing query: ' . $this->connection->error);
        }
        return $result;
    }

    public function getNumRows($result)
    {
        return $result->num_rows;
    }

    public function closeConnection()
    {
        $this->connection->close();
    }
}