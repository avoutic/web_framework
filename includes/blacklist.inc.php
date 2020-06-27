<?php
function add_blacklist_entry($reason, $severity = 1)
{
    global $blacklist, $global_state;

    $user_id = 0;
    if (isset($global_state['user_id']))
        $user_id = $global_state['user_id'];

    $blacklist->add_entry($_SERVER['REMOTE_ADDR'], $user_id, $reason, $severity);
}

class BlackListEntry extends DataCore
{
    static protected $table_name = 'blacklist_entries';
    static protected $base_fields = array('ip', 'user_id', 'severity', 'reason', 'timestamp');
};

class Blacklist extends FrameworkCore
{
    function __construct()
    {
        parent::__construct();

        $this->module_config = $this->config['security']['blacklist'];
    }

    function cleanup()
    {
        $query = <<<SQL
        DELETE FROM blacklist_entries
        WHERE timestamp < ?
SQL;

        $cutoff = time() - $this->module_config['store_period'];

        $result = $this->query($query, array($cutoff));
        verify($result !== false, 'Failed to clean up blacklist entries');
    }

    function add_entry($ip, $user_id, $reason, $severity = 1)
    {
        // Auto cleanup old entries (Over 30 days old)
        //
        $this->cleanup();

        $bt = debug_backtrace();
        $caller = $bt[1];
        $path_parts = pathinfo($caller['file']);
        $file = $path_parts['filename'];
        $full_reason = $file.':'.$caller['line'].':'.$reason;

        $entry = BlacklistEntry::create(array(
                        'ip' => $ip,
                        'user_id' => $user_id,
                        'severity' => $severity,
                        'reason' => $full_reason,
                        'timestamp' => time(),
                    ));
        verify($entry !== false, 'Failed to add blacklist entry');
    }

    function is_blacklisted($ip, $user_id)
    {
        if ($this->module_config['enabled'] == false)
            return false;

        $query = <<<SQL
        SELECT SUM(severity) AS total
        FROM blacklist_entries
        WHERE ( ip = ? OR
                user_id = ?
              ) AND
              timestamp > ?
SQL;

        $cutoff = time() - $this->module_config['trigger_period'];

        $result = $this->query($query, array($ip, $user_id, $cutoff));
        verify($result !== false, 'Failed to sum blacklist entries');

        return $result->fields['total'] > $this->module_config['threshold'];
    }
};
?>
