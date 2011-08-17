<?php
require_once('page_basic.inc.php');

class PageLogoff extends PageBasic
{
    static function get_filter()
    {
        return array(
                'return_page' => FORMAT_RETURN_PAGE,
                );
    }

    function get_title()
    {
        return "Logoff";
    }

    function do_logic()
    {
        $_SESSION['logged_in'] = false;
        $_SESSION['user_id'] = "";
        $_SESSION['permissions'] = array();

        session_destroy();

        header("Location: /".$this->state['input']['return_page']);
    }

    function display_content()
    {
?>
<div>
  Logging off.
</div>
<?
    }
};
?>
