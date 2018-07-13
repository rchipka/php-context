<?php
$GLOBALS['context_wrap'] = array();
$GLOBALS['context_debug'] = false;
$GLOBALS['context_last_logged_key'] = null;
$GLOBALS['context_last_depth'] = 0;
$GLOBALS['log_index'] = 0;
$GLOBALS['print_h1'] = false;
$GLOBALS['enter_callbacks'] = [];
$GLOBALS['context_values'] = [];

class Context {
	public $keys = array();
	public $parent = null;
	public $heading = 0;
	public $object = null;
	public $children = 0;
	public $depth = 0;
	public $_data = [];
	public $cached_data = null;

	public function __construct($key = [], $callback = null) {
		if ($key) {
			if (!is_array($key)) {
				$key = [$key];
			}

			$this->keys = array_merge($this->keys, $key);
		}

    if (is_callable($callback)) {
			try {
				$this->enter();

				call_user_func($callback);

				$this->exit();
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

	public function enter($values = null) {
		$this->parent = $GLOBALS['current_context'];

		$GLOBALS['current_context'] = $this;

		$this->depth = $this->parent->depth + 1;

		$this->children++;

		// if (context_debug()) {
			$this->log_internal('');
		// }

		if (is_array($values)) {
			foreach ($values as $k => $value) {
				$this->{$k} = $value;
			}
		}

		$matches = [];

		foreach ($GLOBALS['enter_callbacks'] as $array) {
			if (($array['match'] = context()->match($array['context']->keys)) > 0) {
				$matches[] = &$array;
			}
		}

		usort($matches, function ($a, $b) {
			return $a['match'] - $b['match'];
		});

		foreach ($matches as $match) {
			$match['callback']($this);
		}

		if (defined( 'WP_DEFAULT_THEME' ) ) {
			do_action('enter_context', $this);
		}

		return $this;
	}

	public function _exit() {
		if (!$this->hasParent()) {
			return;
		}

		$this->log_internal('');

		if (defined( 'WP_DEFAULT_THEME' ) ) {
			do_action('exit_context', $this);
		}

		$this->parent->children--;

		$GLOBALS['current_context'] = $this->parent;

		return ($this->parent);
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

	public function match($args = null, $matchToParent = false, $parentCount = 0) {
		if ($args === null) {
			return $this->match(context()->keys);
		}

		if (!is_array($args)) {
			$args = array_filter([$args]);
		}

		$keys = array_pop($args);

		if (!is_array($keys)) {
			$keys = array_filter([$keys]);
		}

		if (sizeof($keys) < 1) {
			return 0;
		}

		if (context_debug() > 2 && $parentCount === 0) {
			context()->log_internal('attempting to match ' . json_encode($keys) . ' with ' . ($this->path(2)));
		}

		$count = 0;

		foreach ($keys as $key) {
			if (in_array($key, $this->keys)) {
				$count++;
			}
		}

		if ($count >= sizeof($keys)) {
			$count = ($count / sizeof($this->keys));// * $this->depth;

			if (sizeof($args) < 1 || !$this->hasParent()) {
				return $count;
			} else {
				$parent = call_user_func(array($this->parent, 'match'), $args, $matchToParent, $parentCount);

				if ($parent > 0) {
					if ($parentCount == 0) {
						return ($count + $parent); // / $this->depth;
					}

					return $count + $parent;
				}

				return 0;
			}
		}

		if (!$this->hasChildren() && $matchToParent !== true) {
			return 0; // current context must match rightmost keys
		}

		if (!$this->hasParent()) {
			return 0;
		}

		array_push($args, $keys);

		return call_user_func(array($this->parent, 'match'), $args, $matchToParent, $parentCount);
	}

	public function within($args) {
		return $this->match($args, true);
	}


	public function get($key = null, $force_local = false) {
		$value = null;

		if (context() === $this) {
			context()->log('GET ' . json_encode($key) . ' ' . json_encode($this->keys));
		}

		if (context() === $this || $force_local = true) {
			if (is_null($key)) {
				$value = $this->data();
			} else {
				$value = $this->_data[$key];

				if (!$value && $this->hasParent()) {
					$value = $this->parent->get($key, true);
				}
			}
		}

		if ($force_local === true) {
			return $value;
		}

		if (!$value && is_array($GLOBALS['context_values'][$key])) {
			$value = null;
			$max_amount = 0;

			foreach ($GLOBALS['context_values'][$key] as $item) {
				$amount = context()->within($item['context']->keys);

				context()->log($amount . ' - ' . json_encode($item['context']->keys));

				if ($amount > $max_amount) {
					$max_amount = $amount;
					$value = $item['value'];
				}
			}
		}

		if (is_callable($value)) {
			$value = $value();
		}

		return $value;
	}

	public function set($key, $value = null) {
		$self = $this;

		if (sizeof(context()->keys) > 0 && context() === $this) {
			$this->_data[$key] = $value;
		} else {
			if (!$GLOBALS['context_values'][$key]) {
				$GLOBALS['context_values'][$key] = [];
			}

			$GLOBALS['context_values'][$key][] = [
				'context' => $this,
				'value' => $value
			];
		}

		return $this;
	}

	public function data() {
		if ($this->cached_data !== null) {
			return $this->cached_data;
		}

		if ($this->hasParent()) {
			$this->cached_data = array_merge($this->_data, $this->parent->data());
		} else {
			$this->cached_data = $this->_data;
		}

		return $this->cached_data;
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
		if ($action === 'enter') {
			$GLOBALS['enter_callbacks'][] = [
				'context' => $this,
				'callback' => $callback
			];
		} else {
			return $this->add_action($action, $this->keys, $callback);
		}

		return $this;
	}

	public function add_action($action, $keys, $callback, $mode = null, $match_mode = null) {
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

				if (context()->match($keys)) {
					call_user_func_array($callback, $args);
				}
			}, $priority, $reflection->getNumberOfParameters());
	}

	public function wrap($string, $priority = null) {
		$keys = $this->keys;
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

			if ($GLOBALS['context_last_logged_depth'] > 0 && $GLOBALS['context_last_logged_depth'] != $this->depth) {
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

		return $this;
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

