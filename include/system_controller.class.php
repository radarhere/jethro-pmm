<?php
require_once dirname(__FILE__).'/view.class.php';
class System_Controller
{
	var $_view = NULL;
	var $_friendly_errors = false;
	var $_base_dir = '';
	
	static private $instance = NULL;

	/**
	 * Get the instance of the System Controller.
	 *
	 * Singleton pattern.
	 *
	 * @param type $base_dir The base directory.
	 * @return \System_Controller
	 */
	public static function get($base_dir=NULL)
	{
		static $instance = null;

		if ($instance == NULL) {
			$instance = new System_Controller($base_dir);
		}

		return $instance;
	}

	public function __construct($base_dir=NULL)
	{
		if (is_null($base_dir)) $base_dir = dirname(dirname(__FILE__));
		$this->_base_dir = $base_dir;
		$path_sep = defined('PATH_SEPARATOR') ? PATH_SEPARATOR : ((FALSE === strpos($_ENV['OS'], 'Win')) ? ';' : ':');
		ini_set('include_path', ini_get('include_path').$path_sep.$this->_base_dir);

		if (!isset($_SESSION['views'][$base_dir]) || isset($_REQUEST['regen'])) {
			$_SESSION['views'][$base_dir] = Array();
			$dh = opendir($this->_base_dir.'/views');
			while (FALSE !== ($filename = readdir($dh))) {
				if (is_file($this->_base_dir.'/views/'.$filename)) {
					$raw_filenames[] = $filename;
				}
			}
			natsort($raw_filenames);
			foreach ($raw_filenames as $filename) {
				$classname = null;
				if (preg_match('/^view_([0-9]*)_(.*)__([0-9]*)_(.*)\.class\.php/', $filename, $matches)) {
					$classname = 'View_'.$matches[2].'__'.$matches[4];
				} else if (preg_match('/^view_([0-9]*)_(.*)\.class\.php/', $filename, $matches)) {
					if ($matches[1] == 0) $matches[2] = '_'.$matches[2];
					$classname = 'View_'.$matches[2];
				}
				if ($classname) {
					include_once($this->_base_dir.'/views/'.$filename);
					$showView = TRUE;
					if ($view_perm = call_user_func(Array($classname, 'getMenuPermissionLevel'))) {
						$showView = !empty($GLOBALS['user_system']) && $GLOBALS['user_system']->havePerm($view_perm);
					} else if ($view_feature = call_user_func(Array($classname, 'getMenuRequiredFeature'))) {
						$showView = $this->featureEnabled($view_feature);
					}
					if ($showView) {
						if (preg_match('/^view_([0-9]*)_(.*)__([0-9]*)_(.*)\.class\.php/', $filename, $matches)) {
							$_SESSION['views'][$base_dir][$matches[2]]['children'][$matches[4]]['filename'] = $filename;
						} else if (preg_match('/^view_([0-9]*)_(.*)\.class\.php/', $filename, $matches)) {
							if ($matches[1] == 0) $matches[2] = '_'.$matches[2];
							$_SESSION['views'][$base_dir][$matches[2]]['filename'] = $filename;
						}
					}
				}
			}
		}
	}

	public function initErrorHandler()
	{
		set_error_handler(Array($this, '_handleError'));
	}

	public function run()
	{
		if (!empty($_REQUEST['call'])) {
			$call_name = str_replace('/', '', $_REQUEST['call']);
			// Try both the Jethro and system_root calls folders
			$filename = dirname(dirname(__FILE__)).'/calls/call_'.$call_name.'.class.php';
			if (!file_exists($filename)) {
				$filename = $this->_base_dir.'/calls/call_'.$call_name.'.class.php';
			}
			if (file_exists($filename)) {
				include_once dirname(__FILE__).'/call.class.php';
				include_once $filename;
				$classname = 'Call_'.$call_name;
				$call_obj = new $classname;
				$call_obj->run();
			} else {
				trigger_error('Unknown call '.ents($_REQUEST['call']), E_USER_WARNING);
			}
		} else {
			$this->initErrorHandler();
			$raw_view_name = array_get($_REQUEST, 'view', 'home');
			$bits = explode('__', $raw_view_name);
			$view_filename = null;
			if (count($bits) > 1) {
				if (!empty($_SESSION['views'][$this->_base_dir][$bits[0]]['children'][$bits[1]])) {
					$view_filename = $_SESSION['views'][$this->_base_dir][$bits[0]]['children'][$bits[1]]['filename'];
					$view_classname = 'View_'.$bits[0].'__'.$bits[1];
				}
			} else if (isset($_SESSION['views'][$this->_base_dir][$bits[0]])) {
				$view_filename = $_SESSION['views'][$this->_base_dir][$bits[0]]['filename'];
				$view_classname = 'View_'.$bits[0];
			}

			if (!is_null($view_filename)) {
				require_once $this->_base_dir.'/views/'.$view_filename;
				$view_perm = call_user_func(Array($view_classname, 'getMenuPermissionLevel'));
				if (!empty($view_perm) && !$GLOBALS['user_system']->havePerm($view_perm)) {
					trigger_error("You don't have permission to access this view", E_USER_ERROR); // exits
				}
				$this->_view = new $view_classname();
				$this->_view->processView();
			}
			require $this->_base_dir.'/templates/main.template.php';
			restore_error_handler();
		}
	}

	public function getTitle()
	{
		if (is_null($this->_view)) {
			return '';
		} else {
			return $this->_view->getTitle();
		}
	}


	public function printNavigation()
	{
		$current_view = array_get($_REQUEST, 'view', 'home');
		foreach ($_SESSION['views'][$this->_base_dir] as $name => $data) {
			if ($name[0] == '_') continue;
			$class = '';
			if (($current_view == $name) || (strpos($current_view, $name.'__') === 0)) $class = 'active';
			if (empty($data['children'])) {
				// deliberately - only leaf nodes can be navigated to directly
				?>
				<li class="<?php echo $class; ?>">
					<a href="?view=<?php echo $name; ?>" ><?php echo ucwords(str_replace('_', ' ', $name)); ?></a>
				</li>
				<?php
			} else {
				// pardon the formatting - IE is having a white-space tantrum
				?>
				<li class="<?php echo $class; ?> dropdown">
					<a href="javascript:;" class="dropdown-toggle" data-toggle="dropdown"><?php echo ucwords(str_replace('_', ' ', $name)); ?><i class="caret"></i></a>
						<ul class="dropdown-menu"><?php
							foreach ($data['children'] as $subname => $sub_details) {
								$class = ($current_view == $name.'__'.$subname) ? 'active' : '';
								?><li class="<?php echo $class; ?>"><a href="?view=<?php echo $name.'__'.$subname; ?>"><?php echo ucwords(str_replace('_', ' ', $subname)); ?></a></li><?php
							}
							?></ul>
				</li>
				<?php
			}
		}
	}


	public function printBody()
	{
		if (is_null($this->_view)) {
			echo 'Error: Undefined view';
		} else {
			$this->_view->printView();
		}
	}

	public function includeDBClass($classname)
	{
		$classname = strtolower($classname);
		require_once dirname(__FILE__).'/db_object.class.php';
		require_once 'db_objects/'.$classname.'.class.php';
	}

	public function getDBObject($classname, $id)
	{
		$this->includeDBClass($classname);
		$res = new $classname($id);
		if (!$res->id) $res = null;
		return $res;

	}

	public function getDBObjectData($classname, $params=Array(), $logic='OR', $order='')
	{
		$this->includeDBClass($classname);
		$sample = new $classname();
		return $sample->getInstancesData($params, $logic, $order);
	}

	public function doTransaction($operation)
	{
		switch (strtoupper($operation)) {
			case 'BEGIN':
			case 'COMMIT':
			case 'ROLLBACK':
				$r = $GLOBALS['db']->query(strtoupper($operation));
				check_db_result($r);
		}
	}

	public function setFriendlyErrors($enabled)
	{
		$this->_friendly_errors = $enabled;
	}

	public function _handleError($errno, $errstr, $errfile, $errline)
	{
		$send_email = true;
		$exit = false;
		switch ($errno) {
			case E_ERROR:
			case E_USER_ERROR:
				$bg = 'error';
				$title = 'SYSTEM ERROR (ERROR)';
				$exit = true;
				break;
			case E_WARNING:
			case E_USER_WARNING:
				$bg = 'warning';
				$title = 'SYSTEM ERROR (WARNING)';
				break;
			case E_USER_NOTICE: 
				$send_email = false;
				if ($this->_friendly_errors) {
					add_message('Error: '.$errstr, 'failure');
					return;
				}
				// else deliberate fallthrough
			case E_NOTICE:
				$bg = 'info';
				$title = 'SYSTEM ERROR (NOTICE)';
				break;
			default:
				return; // E_STRICT or E_DEPRECATED
		}
		?>
		<div class="alert<?php if(isset($bg)){ echo" alert-".$bg;} ?>">
			<h4><?php echo $title; ?></h4>
			<p><?php echo $errstr; ?></p>
			<u class="clickable" onclick="var parentDiv=this.parentNode; while (parentDiv.tagName != 'DIV') { parentDiv = parentDiv.parentNode; }; with (parentDiv.getElementsByTagName('PRE')[0].style) { display = (display == 'block') ? 'none' : 'block' }">Show Details</u>
			<pre style="display: none; background: white; font-weight: normal; color: black"><b>Line <?php echo $errline; ?> of File <?php echo $errfile; ?></b>
			<?php
			$bt = debug_backtrace();
			foreach ($bt as &$b) {
				if (!empty($b['args'])) {
					foreach ($b['args'] as &$v) {
						if (!is_scalar($v)) $v = '[Object/Array]';
					}
				}
				unset($b['object']);
			}
			print_r($bt); 
			?>
			</pre>
		</div>
		<?php
		if ($send_email && defined('ERRORS_EMAIL_ADDRESS') && constant('ERRORS_EMAIL_ADDRESS')) {
			$content = "$errstr \nLine $errline of $errfile\n\n";
			if (!empty($GLOBALS['user_system'])) $content .= "Current user: ".$GLOBALS['user_system']->getCurrentUser('username');
			$content .= "\n\nRequest: ".print_r($_REQUEST,1)."\n\n".print_r($bt, 1);
			@mail(constant('ERRORS_EMAIL_ADDRESS'), 'Jethro Error from '.build_url(array()), $content);
		}
		if ($send_email) error_log("$errstr - Line $errline of $errfile");
		if ($exit) exit();
	}

	public function runHooks($hook_name, $params)
	{
		require_once 'include/hook.class.php';
		$dir = @opendir(JETHRO_ROOT.'/hooks/'.$hook_name);
		while ($dir && ($hook_file = readdir($dir))) {
			if (is_dir(JETHRO_ROOT.'/hooks/'.$hook_file)) continue;
			if ($hook_file[0] == '.') continue;
			if (0 === strpos($hook_file, 'sample.')) continue;
			require_once 'hooks/'.$hook_name.'/'.$hook_file;
			$class_name = str_replace('.class.php', '', $hook_file);
			call_user_func(Array($class_name, 'run'), $params);
		}
	}

	public function featureEnabled($feature) 
	{
		$enabled_features = explode(',', strtoupper(ENABLED_FEATURES));
		return in_array(strtoupper($feature), $enabled_features);
	}
	
	public static function checkConfigHealth()
	{
		if (REQUIRE_HTTPS && (FALSE === strpos(BASE_URL, 'https://'))) {
			trigger_error("Configuration file error: If you set REQUIRE_HTTPS to true, your BASE_URL must start with https", E_USER_ERROR);
		}
		
		if (substr(BASE_URL, -1) != '/') {
			trigger_error("Configuration file error: Your BASE_URL must end with a slash", E_USER_ERROR);
		}
	}
}
