<?php

namespace WebFramework\Core;

abstract class DataCore extends FrameworkCore
{
    public int $id;

    protected static string $table_name;

    /**
     * @var array<string>
     */
    protected static array $base_fields;
    protected static bool $is_cacheable = false;

    /** @var array<string, null|bool|float|int|string> */
    private array $properties = [];

    public function __construct(int $id, bool $fill_complex = true)
    {
        parent::__construct();

        $this->id = $id;
        $this->fill_fields($fill_complex);
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->properties))
        {
            return $this->properties[$name];
        }

        $this->report_error('Undefined property via __get(): '.$name);

        return null;
    }

    public function __set(string $name, null|bool|float|int|string $value): void
    {
        if (property_exists($this, $name))
        {
            $this->report_error('Inaccessible property via __set(): '.$name);

            return;
        }

        $this->properties[$name] = $value;
    }

    /**
     * @return array<string>
     */
    public function __serialize(): array
    {
        return $this->get_base_fields();
    }

    /**
     * @param array<string> $data
     */
    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);

        $this->id = (int) $data['id'];
        $this->fill_base_fields_from_obj($data);
    }

    public static function exists(int $id): bool
    {
        if (static::$is_cacheable)
        {
            $cache = WF::get_static_cache();

            if ($cache->exists(static::get_cache_id($id)) === true)
            {
                return true;
            }
        }

        $result = WF::get_main_db()->query('SELECT id FROM '.static::$table_name.
                                   ' WHERE id = ?', [$id]);

        if ($result === false)
        {
            return false;
        }

        if ($result->RecordCount() != 1)
        {
            return false;
        }

        return true;
    }

    public static function get_cache_id(int $id): string
    {
        return static::$table_name.'['.$id.']';
    }

    protected function update_in_cache(): void
    {
        if (static::$is_cacheable)
        {
            $this->cache->set(static::get_cache_id($this->id), $this);
        }
    }

    protected function delete_from_cache(): void
    {
        if (static::$is_cacheable)
        {
            $this->cache->invalidate(static::get_cache_id($this->id));
        }
    }

    /**
     * @return array<string>
     */
    public function get_base_fields(): array
    {
        $info = [
            'id' => $this->id,
        ];

        foreach (static::$base_fields as $name)
        {
            $info[$name] = $this->{$name};
        }

        return $info;
    }

    /**
     * @return array<mixed>
     */
    public function get_info(): array
    {
        return $this->get_base_fields();
    }

    /**
     * @return array<mixed>
     */
    public function get_admin_info(): array
    {
        return $this->get_info();
    }

    private function fill_fields(bool $fill_complex): void
    {
        $this->fill_base_fields_from_db();

        if ($fill_complex)
        {
            $this->fill_complex_fields();
        }
    }

    private function fill_base_fields_from_db(): void
    {
        $fields_fmt = implode('`, `', static::$base_fields);
        $table_name = static::$table_name;

        $query = <<<SQL
        SELECT `{$fields_fmt}`
        FROM {$table_name}
        WHERE id = ?
SQL;

        $params = [$this->id];

        $result = $this->query($query, $params);
        $this->verify($result !== false, "Failed to retrieve base fields for {$table_name}");
        $this->verify($result->RecordCount() == 1, "Failed to select single item for {$this->id} in {$table_name}");

        $row = $result->fields;

        foreach (static::$base_fields as $name)
        {
            $this->{$name} = $row[$name];
        }
    }

    /**
     * @param array<string> $fields
     */
    private function fill_base_fields_from_obj(array $fields): void
    {
        foreach (static::$base_fields as $name)
        {
            $this->{$name} = $fields[$name];
        }
    }

    protected function fill_complex_fields(): void
    {
    }

    public function get_field(string $field): string
    {
        $table_name = static::$table_name;

        $query = <<<SQL
        SELECT `{$field}`
        FROM {$table_name}
        WHERE id = ?
SQL;

        $params = [$this->id];

        $result = $this->query($query, $params);
        if ($result === false)
        {
            throw new \RuntimeException("Failed to retrieve {$field} for {$table_name}");
        }

        return $result->fields[$field];
    }

    /**
     * @param array<null|bool|float|int|string> $data
     */
    public function update(array $data): void
    {
        if (count($data) == 0)
        {
            return;
        }

        $table_name = static::$table_name;
        $set_array = static::get_set_fmt($data);
        $params = $set_array['params'];

        $query = <<<SQL
        UPDATE {$table_name}
        SET {$set_array['query']}
        WHERE id = ?
SQL;

        $params[] = $this->id;

        $result = $this->query($query, $params);
        $class = static::class;
        if ($result === false)
        {
            throw new \RuntimeException("Failed to update object ({$class})");
        }

        foreach ($data as $key => $value)
        {
            $this->{$key} = $value;
        }

        $this->update_in_cache();
    }

    public function update_field(string $field, null|bool|float|int|string $value): void
    {
        $table_name = static::$table_name;

        // Mysqli does not accept empty for false, so force to zero
        //
        if ($value === false)
        {
            $value = 0;
        }

        $query = <<<SQL
        UPDATE {$table_name}
        SET `{$field}` = ?
        WHERE id = ?
SQL;

        $params = [$value, $this->id];

        $result = $this->query($query, $params);
        $class = static::class;
        if ($result === false)
        {
            throw new \RuntimeException("Failed to update object ({$class})");
        }

        $this->{$field} = $value;

        $this->update_in_cache();
    }

    public function decrease_field(string $field, int $value = 1, bool $minimum = false): void
    {
        $table_name = static::$table_name;

        $new_value_fmt = '';
        $params = [];

        if ($minimum)
        {
            $new_value_fmt = "GREATEST(?, `{$field}` - ?)";
            $params = [$minimum, $value];
        }
        else
        {
            $new_value_fmt = "`{$field}` - ?";
            $params = [$value];
        }

        $query = <<<SQL
        UPDATE {$table_name}
        SET `{$field}` = {$new_value_fmt}
        WHERE id = ?
SQL;

        $params[] = $this->id;

        $result = $this->query($query, $params);
        $class = static::class;
        if ($result === false)
        {
            throw new \RuntimeException("Failed to decrease field of object ({$class})");
        }

        $this->{$field} = $this->get_field($field);

        $this->update_in_cache();
    }

    public function increase_field(string $field, int $value = 1): void
    {
        $table_name = static::$table_name;

        $query = <<<SQL
        UPDATE {$table_name}
        SET `{$field}` = `{$field}` + ?
        WHERE id = ?
SQL;

        $params = [$value, $this->id];

        $result = $this->query($query, $params);
        $class = static::class;
        if ($result === false)
        {
            throw new \RuntimeException("Failed to increase field of object ({$class})");
        }

        $this->{$field} = $this->get_field($field);

        $this->update_in_cache();
    }

    public function delete(): void
    {
        $table_name = static::$table_name;

        $this->delete_from_cache();

        $query = <<<SQL
        DELETE FROM {$table_name}
        WHERE id = ?
SQL;

        $params = [$this->id];

        $result = $this->query($query, $params);
        if ($result === false)
        {
            throw new \RuntimeException('Failed to delete item');
        }
    }

    /**
     * @param array<null|bool|float|int|string> $data
     *
     * @return static
     */
    public static function create(array $data): self
    {
        $table_name = static::$table_name;
        $query = '';
        $params = [];

        if (count($data) == 0)
        {
            $query = <<<SQL
        INSERT INTO {$table_name}
        VALUES()
SQL;
        }
        else
        {
            $set_array = static::get_set_fmt($data);
            $params = $set_array['params'];

            $query = <<<SQL
        INSERT INTO {$table_name}
        SET {$set_array['query']}
SQL;
        }

        $result = WF::get_main_db()->insert_query($query, $params);
        $class = static::class;
        if ($result === false)
        {
            throw new \RuntimeException("Failed to create object ({$class})");
        }

        $obj = static::get_object_by_id($result, true);
        if ($obj === false)
        {
            throw new \RuntimeException("Failed to retrieve created object ({$class})");
        }

        return $obj;
    }

    /**
     * @param array<string, null|bool|float|int|string> $filter
     */
    public static function count_objects(array $filter = []): int
    {
        $table_name = static::$table_name;

        $params = [];
        $where_fmt = '';

        if (count($filter))
        {
            $filter_array = static::get_filter_array($filter);
            $where_fmt = "WHERE {$filter_array['query']}";
            $params = $filter_array['params'];
        }

        $query = <<<SQL
        SELECT COUNT(id) AS cnt
        FROM {$table_name}
        {$where_fmt}
SQL;

        $result = WF::get_main_db()->query($query, $params);
        $class = static::class;

        if ($result === false)
        {
            throw new \RuntimeException("Failed to retrieve object ({$class})");
        }
        if ($result->RecordCount() != 1)
        {
            throw new \RuntimeException("Failed to count objects ({$class})");
        }

        return $result->fields['cnt'];
    }

    // This is the base retrieval function that all object functions should use
    // Cache checking is done here
    //
    /**
     * @return false|static
     */
    public static function get_object_by_id(int $id, bool $checked_presence = false): false|DataCore
    {
        if (static::$is_cacheable)
        {
            $cache = WF::get_static_cache();
            $obj = $cache->get(static::get_cache_id($id));

            // Cache hit
            //
            if ($obj !== false)
            {
                return $obj;
            }
        }

        $class = static::class;

        if ($checked_presence == false)
        {
            $table_name = static::$table_name;

            $query = <<<SQL
            SELECT id
            FROM {$table_name}
            WHERE id = ?
SQL;

            $params = [$id];

            $result = WF::get_main_db()->query($query, $params);

            if ($result === false)
            {
                throw new \RuntimeException("Failed to retrieve object ({$class})");
            }
            if ($result->RecordCount() > 1)
            {
                throw new \RuntimeException("Non-unique object request ({$class})");
            }

            if ($result->RecordCount() == 0)
            {
                return false;
            }
        }

        $obj = new $class($id);

        // Cache miss
        //
        $obj->update_in_cache();

        return $obj;
    }

    // Helper retrieval functions
    //
    /**
     * @param array<null|bool|float|int|string> $filter
     *
     * @return false|static
     */
    public static function get_object(array $filter = []): false|DataCore
    {
        $table_name = static::$table_name;

        $params = [];
        $where_fmt = '';

        if (count($filter))
        {
            $filter_array = static::get_filter_array($filter);
            $where_fmt = "WHERE {$filter_array['query']}";
            $params = $filter_array['params'];
        }

        $query = <<<SQL
        SELECT id
        FROM {$table_name}
        {$where_fmt}
SQL;

        $result = WF::get_main_db()->query($query, $params);
        $class = static::class;

        if ($result === false)
        {
            throw new \RuntimeException("Failed to retrieve object ({$class})");
        }
        if ($result->RecordCount() > 1)
        {
            throw new \RuntimeException("Non-unique object request ({$class})");
        }

        if ($result->RecordCount() == 0)
        {
            return false;
        }

        return static::get_object_by_id($result->fields['id'], true);
    }

    /**
     * @param array<null|bool|float|int|string> $filter
     *
     * @return array<mixed>
     */
    public static function get_object_info(array $filter = []): false|array
    {
        return static::get_object_data('get_info', $filter);
    }

    /**
     * @param array<null|bool|float|int|string> $filter
     *
     * @return array<mixed>|false
     */
    public static function get_object_data(string $data_function, array $filter = []): false|array
    {
        $obj = static::get_object($filter);

        if ($obj === false)
        {
            return false;
        }

        return $obj->{$data_function}();
    }

    /**
     * @return array<mixed>|false
     */
    public static function get_object_info_by_id(int $id): false|array
    {
        return static::get_object_data_by_id('get_info', $id);
    }

    /**
     * @return array<mixed>|false
     */
    public static function get_object_data_by_id(string $data_function, int $id): false|array
    {
        $obj = static::get_object_by_id($id);

        if ($obj === false)
        {
            return false;
        }

        return $obj->{$data_function}();
    }

    /**
     * @param array<null|bool|float|int|string> $filter
     *
     * @return array<static>
     */
    public static function get_objects(int $offset = 0, int $results = 10, array $filter = [], string $order = ''): array
    {
        $table_name = static::$table_name;

        $params = [];
        $where_fmt = '';

        if (count($filter))
        {
            $filter_array = static::get_filter_array($filter);
            $where_fmt = "WHERE {$filter_array['query']}";
            $params = $filter_array['params'];
        }

        $order_fmt = (strlen($order)) ? "ORDER BY {$order}" : '';
        $limit_fmt = '';

        if ($results != -1)
        {
            $limit_fmt = 'LIMIT ?,?';
            $params[] = (int) $offset;
            $params[] = (int) $results;
        }

        $query = <<<SQL
        SELECT id
        FROM {$table_name}
        {$where_fmt}
        {$order_fmt}
        {$limit_fmt}
SQL;

        $container = \ContainerWrapper::get();
        $result = $container->get(Database::class)->query($query, $params);
        $class = static::class;
        if ($result === false)
        {
            throw new \RuntimeException("Failed to retrieve objects ({$class})");
        }

        $info = [];
        foreach ($result as $k => $row)
        {
            $obj = static::get_object_by_id($row['id'], true);
            if ($obj === false)
            {
                throw new \RuntimeException("Failed to retrieve {$class}");
            }

            $info[$row['id']] = $obj;
        }

        return $info;
    }

    /**
     * @param array<null|bool|float|int|string> $filter
     *
     * @return array<mixed>
     */
    public static function get_objects_info(int $offset = 0, int $results = 10, array $filter = [], string $order = ''): array
    {
        return static::get_objects_data('get_info', $offset, $results, $filter, $order);
    }

    /**
     * @param array<null|bool|float|int|string> $filter
     *
     * @return array<mixed>
     */
    public static function get_objects_data(string $data_function, int $offset = 0, int $results = 10, array $filter = [], string $order = ''): array
    {
        $objs = static::get_objects($offset, $results, $filter, $order);

        $data = [];
        foreach ($objs as $obj)
        {
            $data[] = $obj->{$data_function}();
        }

        return $data;
    }

    /**
     * @param array<null|bool|float|int|string> $values
     *
     * @return array{query: string, params: array<bool|float|int|string>}
     */
    public static function get_set_fmt(array $values): array
    {
        $set_fmt = '';
        $params = [];
        $first = true;

        foreach ($values as $key => $value)
        {
            if (!$first)
            {
                $set_fmt .= ', ';
            }
            else
            {
                $first = false;
            }

            // Mysqli does not accept empty for false, so force to zero
            //
            if ($value === false)
            {
                $value = 0;
            }

            if ($value === null)
            {
                $set_fmt .= "`{$key}` = NULL";
            }
            else
            {
                $set_fmt .= "`{$key}` = ?";
                $params[] = $value;
            }
        }

        return [
            'query' => $set_fmt,
            'params' => $params,
        ];
    }

    /**
     * @param array<null|bool|float|int|string> $filter
     *
     * @return array{query: string, params: array<bool|float|int|string>}
     */
    public static function get_filter_array(array $filter): array
    {
        $filter_fmt = '';
        $params = [];
        $first = true;

        foreach ($filter as $key => $value)
        {
            if (!$first)
            {
                $filter_fmt .= ' AND ';
            }
            else
            {
                $first = false;
            }

            // Mysqli does not accept empty for false, so force to zero
            //
            if ($value === false)
            {
                $value = 0;
            }

            if ($value === null)
            {
                $filter_fmt .= "`{$key}` IS NULL";
            }
            else
            {
                $filter_fmt .= "`{$key}` = ?";
                $params[] = $value;
            }
        }

        return [
            'query' => $filter_fmt,
            'params' => $params,
        ];
    }

    public function to_string(): string
    {
        $vars = call_user_func('get_object_vars', $this);
        WFHelpers::scrub_state($vars);

        return $vars;
    }
}
