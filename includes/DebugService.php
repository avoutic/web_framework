<?php

namespace WebFramework\Core;

use Slim\Psr7\Request;

class DebugService
{
    private ?Database $database = null;

    public function __construct(
        private WF $framework,
        private string $server_name
    ) {
    }

    public function set_database(Database $database): void
    {
        $this->database = $database;
    }

    // Generate Cache hash
    //
    public function generate_hash(string $server_name, string $request_source, string $file, int $line, string $message): string
    {
        $key = "{$server_name}:{$request_source}:{$file}:{$line}:{$message}";

        return sha1($key);
    }

    /**
     * @param array<mixed> $trace
     *
     * @return array{title: string, low_info_message: string, message: string, hash: string}
     */
    public function get_error_report(array $trace, ?Request $request, string $error_type, string $message): array
    {
        $info = [
            'title' => "{$this->server_name} - {$error_type}: {$message}",
            'low_info_message' => '',
            'message' => '',
            'hash' => '',
        ];

        // Retrieve request
        //
        $request_source = 'app';
        if ($this->server_name !== 'app' && $request !== null)
        {
            $request_method = $request->getMethod();

            $uri = (string) $request->getUri();

            $request_source = $request_method.' '.$uri;
        }

        $stack = $this->filter_trace($trace);
        $stack_top = reset($stack);

        $file = ($stack_top) ? $stack_top['file'] : 'unknown';
        $line = ($stack_top) ? $stack_top['line'] : 0;

        // Cache hash
        //
        $info['hash'] = $this->generate_hash($this->server_name, $request_source, $file, $line, $message);

        // Construct base message
        //
        $info['low_info_message'] = <<<TXT
File: {$file}
Line: {$line}

TXT;

        $error_type = WFHelpers::get_error_type_string($error_type);
        $condensed_stack = $this->condense_stack($stack);

        $db_error = $this->get_database_error($this->database);

        $input_report = "No request\n";
        $headers_fmt = "No request\n";
        $server_fmt = "No request\n";

        if ($request !== null)
        {
            $input_report = $this->get_inputs_report($request);
            $headers = $request->getHeaders();
            $headers = $this->scrub_request_headers($headers);
            $headers_fmt = print_r($headers, true);
            $server_fmt = print_r($request->getServerParams(), true);
        }

        $auth_data = $this->get_authentication_status();
        $stack_fmt = (count($stack)) ? print_r($stack, true) : "No stack\n";

        $info['message'] .= <<<TXT
File: {$file}
Line: {$line}
ErrorType: {$error_type}
Message: {$message}

Server: {$this->server_name}
Request: {$request_source}

Condensed backtrace:
{$condensed_stack}
Last Database error:
{$db_error}

Inputs:
{$input_report}
Auth:
{$auth_data}
Backtrace:
{$stack_fmt}
Headers:
{$headers_fmt}
Server:
{$server_fmt}
TXT;

        return $info;
    }

    /**
     * @param array<array<mixed>> $trace
     *
     * @return array<array<mixed>>
     */
    public function filter_trace(array $trace, bool $skip_internal = true, bool $scrub_state = true): array
    {
        $stack = [];
        $skipping = $skip_internal;

        foreach ($trace as $entry)
        {
            if ($skipping
                && in_array($entry['class'], [
                    'FrameworkAssertService',
                    'DebugService',
                ]))
            {
                continue;
            }

            $skipping = false;

            if (in_array($entry['function'], ['exit_send_error', 'exit_error']))
            {
                unset($entry['args']);
            }

            $stack[] = $entry;
        }

        if ($scrub_state)
        {
            WFHelpers::scrub_state($stack);
        }

        return $stack;
    }

    /**
     * @param array<array<mixed>> $stack
     */
    public function condense_stack(array $stack): string
    {
        $stack_condensed = '';

        foreach ($stack as $entry)
        {
            $stack_condensed .= $entry['file'].'('.$entry['line'].'): ';

            if (isset($entry['class']))
            {
                $stack_condensed .= $entry['class'].$entry['type'];
            }

            $stack_condensed .= $entry['function']."()\n";
        }

        return $stack_condensed;
    }

    // Retrieve database status
    //
    public function get_database_error(?Database $database): string
    {
        if ($database === null)
        {
            return 'Not initialized yet';
        }

        $db_error = $database->get_last_error();

        if (strlen($db_error))
        {
            return $db_error;
        }

        return 'None';
    }

    // Retrieve auth data
    //
    public function get_authentication_status(): string
    {
        $auth_data = "Not authenticated\n";

        if ($this->framework->is_authenticated())
        {
            $auth_array = $this->framework->get_authenticated();
            WFHelpers::scrub_state($auth_array);

            $auth_data = print_r($auth_array, true);
        }

        return $auth_data;
    }

    // Retrieve inputs
    //
    public function get_inputs_report(Request $request): string
    {
        $inputs_fmt = '';

        // Get the GET parameters
        //
        $get_params = $request->getQueryParams();

        if (count($get_params))
        {
            $get_fmt = print_r($get_params, true);

            $inputs_fmt .= <<<TXT
GET:
{$get_fmt}

TXT;
        }

        // Check if the Content-Type header indicates JSON data
        //
        $content_type = $request->getHeaderLine('Content-Type');
        $is_json_data = str_contains($content_type, 'application/json');

        if ($is_json_data)
        {
            // Get the message body as a string
            //
            $body = (string) $request->getBody();

            // Parse the JSON content
            //
            $json_data = json_decode($body, true);

            if ($json_data === null && json_last_error() !== JSON_ERROR_NONE)
            {
                // Error parsing
                //
                $inputs_fmt .= <<<TXT
JSON parsing failed:
{$body}

TXT;
            }
            else
            {
                $json_fmt = print_r($json_data, true);

                $inputs_fmt .= <<<TXT
JSON data:
{$json_fmt}

TXT;
            }
        }

        // Check if parsed body data is available
        //
        $post_params = $request->getParsedBody();
        if ($post_params !== null)
        {
            $post_fmt = print_r($post_params, true);

            $inputs_fmt .= <<<TXT
POST:
{$post_fmt}

TXT;
        }

        return strlen($inputs_fmt) ? $inputs_fmt : "No inputs\n";
    }

    /**
     * @param array<array<mixed>> $headers
     *
     * @return array<array<mixed>>
     */
    public function scrub_request_headers(array $headers): array
    {
        foreach ($headers as $name => $values)
        {
            // Exclude cookie headers
            //
            if (strtolower($name) === 'cookie')
            {
                unset($headers[$name]);
            }
        }

        return $headers;
    }
}