<?php
$GLOBALS['context_wrap'] = array();
$GLOBALS['context_debug'] = false;
$GLOBALS['context_last_logged_key'] = null;
$GLOBALS['context_last_depth'] = 0;
$GLOBALS['log_index'] = 0;
$GLOBALS['print_h1'] = false;

class Context {
	public $keys = array();
	public $parent = null;
	public $heading = 0;
	public $object = null;
	public $children = 0;
	public $depth = 0;

	public function __construct($key = [], $callback = null) {
		if ($key) {
			if (!is_array($key)) {
				$key = [$key];
			}

			$this->keys = array_merge($this->keys, $key);
		}

    if (is_callable($callback)) {
			try {

	    } catch (\Error $e) {
	    	error_log(json_encode($e));
			}
		}
	}

	function __call($method, $args) {
		if ($method === 'exit' || $method === 'print') {
			return call_user_func_array(array($this, '_' . $method), $args);
		}

		if (!is_callable($this->{$method})) {
			$this->log_internal('No such method ' . json_encode($method));
			return;
		}

		return call_user_func_array(array($this, $method), $args);
	}

	public function path($limit = null, $count = 0) {
		$path = '/' . implode('.', $this->keys);

		if ($limit !== null && ++$count > $limit) {
			return '';
		}

		if ($this->hasParent()) {
			return $this->parent->path($limit, $count) . $path;
		}

		return $path;
	}

	public function hasParent() {
		return ($this->parent instanceof Context);
	}

	public function hasChildren() {
		return ($this->children > 0);
	}

	public function enter($values = null) {
		$this->parent = $GLOBALS['current_context'];

		$context = $GLOBALS['current_context'] = new Context($key);

		$context->parent = $this;
		$context->depth = $this->depth + 1;

		$this->children++;

		if (context_debug()) {
			$this->log_internal('');
		}

		if (is_array($values)) {
			foreach ($values as $k => $value) {
				$context->{$k} = $value;
			}
		}

		do_action('enter_context', $context);

		return $context;
	}

	public function _exit() {
		if (!$this->hasParent()) {
			return;
		}

		$this->log_internal('');

		do_action('exit_context', $this);

		$this->parent->children--;

		$GLOBALS['current_context'] = $this->parent;

		return ($this->parent);
	}

	public function find($args) {
		$ctx = $this;

		while ($ctx) {
			if (call_user_func(array($ctx, 'match'), $keys, false)) {
				break;
			}

			if (!$ctx->hasParent()) {
				break;
			}

			$ctx = $ctx->parent;
		}

		return $ctx;
	}

	public function match($args, $mode = 'any') {
		if (!is_array($args)) {
			$args = array_filter([$args]);
		}

		$keys = array_pop($args);

		if (!is_array($keys)) {
			$keys = array_filter([$keys]);
		}

		if (sizeof($keys) < 1) {
			return true;
		}

		if (context_debug() > 2) {
			context()->log_internal('attempting to match ' . json_encode($keys) . ' with ' . ($this->path(2)));
		}

		// TODO: Allow `or` selectors by changing min to 1
		$min = sizeof($keys);
		$count = 0;

		foreach ($keys as $key) {
			if (in_array($key, $this->keys)) {
				$count++;
			}
		}

		if ($count >= $min) {
			if (sizeof($args) < 1 || !$this->hasParent()) {
				return true;
			} else {
				return call_user_func(array($this->parent, 'match'), $args, $mode);
			}
		}

		if (!$this->hasChildren() && $mode != 'any') {
			return false; // current context must match rightmost keys
		}

		if (!$this->hasParent()) {
			return false;
		}

		array_push($args, $keys);

		return call_user_func(array($this->parent, 'match'), $args, $mode);
	}

	public function add_filter($filter, $keys, $callback, $mode = null) {
		$priority = 5;

		if ($mode === 'before') {
			$priority = 10;
		} else if ($mode === 'after') {
			$priority = 10;
		} else if (is_numeric($mode)) {
			$priority = intval($mode);
		}

		if (!is_array($keys)) {
			$keys = [$keys];
		}

		if (context_debug() > 1) {
			context()->log('add_filter ' . json_encode($filter) . ' ' . json_encode($keys));
		}

		$reflection = (new ReflectionFunction($callback));

		add_filter($filter, function () use ($filter, $keys, $callback, $mode, $reflection) {
				$args = func_get_args();

				// context()->enter($filter);

				if (call_user_func(array(context(), 'match'), $keys)) {
					ob_start();

					if (context_debug()) {
						context()->log('do_filter ' . json_encode($filter) . ' ' . json_encode($keys) . ' ' . $reflection->getName() . ' ' . str_replace($_SERVER['DOCUMENT_ROOT'], '', $reflection->getFileName()));
					}

					$ret = call_user_func_array($callback, $args);

					if (!$ret) {
						$ret = trim(ob_get_contents());
					}

					ob_end_clean();

					// context()->exit();

					if ($mode === 'before') {
						return $ret . $args[0];
					} else if ($mode === 'after') {
						return $args[0] . $ret;
					}

					return $ret;
				} else {
					// context()->exit();
				}

				return $args[0];
			}, $priority, $reflection->getNumberOfParameters());
	}

	public function prepend_filter($filter, $keys, $callback) {
		return $this->add_filter($filter, $keys, $callback, 'before');
	}

	public function append_filter($filter, $keys, $callback) {
		return $this->add_filter($filter, $keys, $callback, 'after');
	}

	public function before_filter($filter, $keys, $callback) {
		return $this->add_filter($filter, $keys, $callback, 'before');
	}

	public function after_filter($filter, $keys, $callback) {
		return $this->add_filter($filter, $keys, $callback, 'after');
	}

	public function get_parent_heading() {
		$parent = $this->parent;

		while ($parent) {
			if ($parent->heading > 0) {
				return $parent->heading;
			}

			$parent = $parent->parent;
		}

		return 0;
	}

	public function wrap_heading($string, $class = null) {
		if ($this->hasParent() && $this->heading < 1) {
			$this->heading = $this->get_parent_heading() + 1;
		}

		if ($this->heading < 1) {
			$this->heading = 1;
		}

		if ($this->heading === 1) {
			if ($GLOBALS['print_h1']) {
				$this->heading = 2;
			} else {
				$GLOBALS['print_h1'] = true;
			}
		}

		$ret = '<h' . $this->heading;

			$class = trim('heading ' . $class);

			if ($class) {
				if (is_array($class)) {
					$class = implode(' ', $class);
				}

				$ret .= ' class="' . $class . '"';
			}

			$ret .= '>' . $string . '</h' . $this->heading . '>';

		return $ret;
	}

	public function before($action, $keys, $callback = null) {
		if ($callback === null) {
			return $this->before('enter', $action, $keys);
		}

		return $this->add_action($action, $keys, $callback, 'before_wrap');
	}

	public function after($action, $keys, $callback = null) {
		if ($callback === null) {
			return $this->after('exit', $action, $keys);
		}

		return $this->add_action($action, $keys, $callback, 'after_wrap');
	}

	public function on($action, $callback) {
		return $this->add_action($action, $this->keys, $callback);
	}

	public function add_action($action, $keys, $callback, $mode = null, $match_mode = 'any') {
		$priority = 5;

		if (!is_array($keys)) {
			$keys = array_filter([$keys]);
		}

		if ($mode == 'before_wrap') {
			$priority = 0;
		} else if ($mode == 'after_wrap') {
			$priority = 10;
		} else if (is_numeric($mode)) {
			$priority = intval($mode);
		}

		if ($action == 'enter' || $action == 'exit') {
			$action .= '_context';

			if (!preg_match('/^/', $action)) {
				$action = '' . $action;
			}

			$match_mode = false;
		}

		$reflection = (new ReflectionFunction($callback));

		add_action($action, function () use ($keys, $callback, $mode) {
				$args = func_get_args();

				if (call_user_func(array(context(), 'match'), $keys, $match_mode)) {
					call_user_func_array($callback, $args);
				}
			}, $priority, $reflection->getNumberOfParameters());
	}

	public function wrap($keys, $string, $priority = null) {
		$wrap_key = rand(0, 9999999) . '';

		$this->add_action('enter_context', $keys, function () use ($string, $wrap_key, $keys) {
				if (is_callable($string)) {
					ob_start();

					$string = call_user_func($string);

					if (!$string) {
						$string = trim(ob_get_contents());
					}

					ob_end_clean();
				}

				$GLOBALS['context_wrap'][$wrap_key] = split_wrap($string);

				echo $GLOBALS['context_wrap'][$wrap_key][0];
			}, $priority, false);

		$this->add_action('exit_context', $keys, function () use ($string, $wrap_key, $keys) {
				echo $GLOBALS['context_wrap'][$wrap_key][1];
			}, $priority, false);
	}

	public function _print($array, $object = null) {
		$keys = apply_filters('print_context', array_keys($array));

		if (!$object) {
			$object = $this->object;
		}

		foreach ($keys as $key) {
			$value = apply_filters('' . $key, $array[$key], $object);

			if (!is_string($value) && !is_numeric($value)) {
				if (is_array($value) && $value['id'] && $value['width'] && $value['height'] && $value['mime_type']) {
					$value = wp_get_attachment_image($value['id'], apply_filters('image_size', 'thumbnail'));
				} else {
					$value = '<pre style="display:block;background-color:#FFF;color:#FFF;"><code style="display:block;">' . print_r($value, 1) . '</code></pre>';
				}
			}

			echo $value;
		}
	}

	public function object($object) {
		$this->object = $object;

		return $this;
	}

	public function log($string) {
		$key = $this->path(1);
		$string = trim($string);

		$pad_space = ' ' . ' ';

		$pad = str_replace(str_repeat(' ', 5), str_repeat(' ', 3) . '| ', str_repeat($pad_space, $this->depth));
		$last_depth = $GLOBALS['context_last_logged_depth'];
		$last_pad = str_replace(str_repeat(' ', 5), str_repeat(' ', 3) . '| ', str_repeat($pad_space, $last_depth));
		$space = ' | ';

		if ($key == $GLOBALS['context_last_logged_key']) {
			$key = ' |';

			if (strlen(trim($string)) < 1) {
				return;
			}
			// error_log($pad . ' |');
		} else {
			$GLOBALS['context_last_logged_key'] = $key;

			$key = preg_replace('/^\//', '', $key);

			if ($GLOBALS['context_last_logged_depth'] > 0) {
				if ($this->depth > $GLOBALS['context_last_logged_depth']) {
					$log = $last_pad . ' \\';
					$key = '\\ ' . $key;
				} else {
					$log = $last_pad . '/';
					$key = ' / ';
				}

				error_log(str_pad($log, 50, ' ', STR_PAD_RIGHT) . $space);
			}
		}

		$GLOBALS['context_last_logged_depth'] = $this->depth;
		$key = str_pad($pad . $key, 50, ' ', STR_PAD_RIGHT);

		$string = wordwrap($string, 80, "\n", true);
		$lines = array_filter(explode("\n", $string));

		if (strlen($key) > 50 || sizeof($lines) > 1) {
			error_log($key);
			$key = str_pad($pad . ' |', 50, ' ', STR_PAD_RIGHT);
		}

		if (sizeof($lines) > 0) {
			$space = ' > ';
		}

		if (sizeof($lines) < 1) {
			$lines = [''];
		}

		foreach ($lines as $i => $line) {
			if ($i > 0) {
				$space = ' | ' . ' ';
			}

			error_log($key . $space . $line);
		}
	}

	public function log_internal($string) {
		return $this->log($string);
	}
};

$GLOBALS['current_context'] = new Context();

function context_log($string) {
	context()->log($string);
}

function context_debug($value = null) {
	if ($value === null) {
		return $GLOBALS['context_debug'];
	}

	return ($GLOBALS['context_debug'] = $value);
}

function context($keys = null, $callback = null) {
	if ($keys === null) {
		return $GLOBALS['current_context'];
	}

	return new Context($keys, $callback);
}

function split_wrap($string) {
	$string = trim($string);

	return explode('%%', $string);
}
