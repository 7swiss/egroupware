<?php
/**
 * EGroupware API: Database backups
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package api
 * @subpackage db
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @copyright (c) 2003-16 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @version $Id$
 */

namespace EGroupware\Api\Db;

use EGroupware\Api;
use ZipArchive;

/**
 * DB independent backup and restore of EGroupware database
 *
 * Backing up bool columns now for all databases as 1 or 0, but understanding PostgreSQL 't' or 'f' too.
 */
class Backup
{
	/**
	 * Configuration table.
	 */
	const TABLE = 'egw_config';
	/**
	 * Reference to schema_proc
	 *
	 * @var Api\Db\Schema
	 */
	var $schema_proc;
	/**
	 * Reference to ADOdb (connection) object
	 *
	 * @var ADOConnection
	 */
	var $adodb;
	/**
	 * DB schemas, as array tablename => schema
	 *
	 * @var array
	 */
	var $schemas = array();
	/**
	 * Tables to exclude from the backup: sessions, diverse caches which get automatic rebuild
	 *
	 * @var array
	 */
	var $exclude_tables = array(
		'egw_sessions','egw_app_sessions','phpgw_sessions','phpgw_app_sessions',	// eGW's session-tables
		'phpgw_anglemail',	// email's cache
		'egw_felamimail_cache','egw_felamimail_folderstatus','phpgw_felamimail_cache','phpgw_felamimail_folderstatus',	// felamimail's cache
		'egw_phpfreechat', // as of the fieldnames of the table a restore would fail within egroupware, and chatcontent is of no particular intrest
	);
	/**
	 * regular expression to identify system-tables => ignored for schema+backup
	 *
	 * @var string|boolean
	 */
	var $system_tables = false;
	/**
	 * Regular expression to identify eGW tables => if set only they are used
	 *
	 * @var string|boolean
	 */
	var $egw_tables = false;
	/**
	 * Backup directory.
	 *
	 * @var string
	 */
	var $backup_dir;
	/**
	 * Minimum number of backup files to keep. Zero for: Disable cleanup.
	 *
	 * @var integer
	 */
	var $backup_mincount;
	/**
	 * Backup Files config value, will be overwritten by the availability of the ZibArchive libraries
	 *
	 * @var boolean
	 */
	var $backup_files = false ;
	/**
	 * Reference to schema_proc's Api\Db object
	 *
	 * @var Api\Db
	 */
	var $db;

	/**
	 * Constructor
	 */
	function __construct()
	{
		if (isset($GLOBALS['egw_setup']) && is_object($GLOBALS['egw_setup']) && !isset($GLOBALS['egw_setup']->db))
		{
			$GLOBALS['egw_setup']->loaddb();	// we run inside setup, but db object is not loaded
		}
		if (isset($GLOBALS['egw_setup']->oProc) && is_object($GLOBALS['egw_setup']->oProc))	// schema_proc already instanciated, use it
		{
			$this->schema_proc = $GLOBALS['egw_setup']->oProc;
		}
		else
		{
			$this->schema_proc = new Api\Db\Schema();
		}

		$this->db = $this->schema_proc->m_odb;
		if (!$this->db->Link_ID) $this->db->connect();
		$this->adodb = $this->db->Link_ID;
		if (isset($GLOBALS['egw_setup']) && is_object($GLOBALS['egw_setup']))		// called from setup
		{
			if ($GLOBALS['egw_setup']->config_table && $GLOBALS['egw_setup']->table_exist(array($GLOBALS['egw_setup']->config_table)))
			{
				if (!($this->files_dir = $this->db->query("SELECT config_value FROM {$GLOBALS['egw_setup']->config_table} WHERE config_app='phpgwapi' AND config_name='files_dir'",__LINE__,__FILE__)->fetchColumn()))
				{
					error_log(__METHOD__."->"."No files Directory set/found");
				}
				if (!($this->backup_dir = $this->db->query("SELECT config_value FROM {$GLOBALS['egw_setup']->config_table} WHERE config_app='phpgwapi' AND config_name='backup_dir'",__LINE__,__FILE__)->fetchColumn()))
				{
					$this->backup_dir = $this->files_dir.'/db_backup';
				}
				$this->charset = $this->db->query("SELECT config_value FROM {$GLOBALS['egw_setup']->config_table} WHERE config_app='phpgwapi' AND config_name='system_charset'",__LINE__,__FILE__)->fetchColumn();
				$this->api_version = $this->db->select($GLOBALS['egw_setup']->applications_table,'app_version',array('app_name'=>array('api','phpgwapi')),
					__LINE__,__FILE__,0,'ORDER BY app_name ASC')->fetchColumn();
				// Backup settings
				$this->backup_mincount = $this->db->query("SELECT config_value FROM {$GLOBALS['egw_setup']->config_table} WHERE config_app='phpgwapi' AND config_name='backup_mincount'",__LINE__,__FILE__)->fetchColumn();
				// backup files too
				$this->backup_files = (bool)$this->db->query("SELECT config_value FROM {$GLOBALS['egw_setup']->config_table} WHERE config_app='phpgwapi' AND config_name='backup_files'",__LINE__,__FILE__)->fetchColumn();
			}
			if (!$this->charset) $this->charset = 'utf-8';
		}
		else	// called from eGW
		{
			if (!($this->backup_dir = $GLOBALS['egw_info']['server']['backup_dir']))
			{
				$this->backup_dir = $GLOBALS['egw_info']['server']['files_dir'].'/db_backup';
			}
			$this->files_dir = $GLOBALS['egw_info']['server']['files_dir'];
			$this->backup_mincount = $GLOBALS['egw_info']['server']['backup_mincount'];
			$this->backup_files = $GLOBALS['egw_info']['server']['backup_files'];
			$this->charset = Api\Translation::charset();

			$this->api_version = $GLOBALS['egw_info']['apps'][isset($GLOBALS['egw_info']['apps']['api'])?'api':'phpgwapi']['version'];
		}
		// Set a default value if not set.
		if (!isset($this->backup_mincount))
		{
			$this->backup_mincount = 0; // Disabled if not set
		}
		if (!isset($this->backup_files))
		{
			$this->backup_files = false; // Disabled if not set
		}

		if (!is_dir($this->backup_dir) && is_writable(dirname($this->backup_dir)))
		{
			mkdir($this->backup_dir);
		}
		switch($this->db->Type)
		{
			case 'sapdb':
			case 'maxdb':
				//$this->system_tables = '/^(sql_cursor.*|session_roles|activeconfiguration|cachestatistics|commandcachestatistics|commandstatistics|datastatistics|datavolumes|hotstandbycomponent|hotstandbygroup|instance|logvolumes|machineconfiguration|machineutilization|memoryallocatorstatistics|memoryholders|omslocks|optimizerinformation|sessions|snapshots|spinlockstatistics|version)$/i';
				$this->egw_tables = '/^(egw_|phpgw_|g2_)/i';
				break;
		}
	}

	/**
	 * Opens the backup-file using the highest available compression
	 *
	 * @param $name =false string/boolean filename to use, or false for the default one
	 * @param $reading =false opening for reading ('rb') or writing ('wb')
	 * @return string/resource/zip error-msg of file-handle
	 */
	function fopen_backup($name=false,$reading=false)
	{
		//echo "function fopen_backup($name,$reading)<br>";	// !
		if (!$name)
		{
			//echo '-> !$name<br>';	// !
			if (!$this->backup_dir || !is_writable($this->backup_dir))
			{
				//echo '   -> !$this->backup_dir || !is_writable($this->backup_dir)<br>';	// !
				return lang("backupdir '%1' is not writeable by the webserver",$this->backup_dir);
			}
			$name = $this->backup_dir.'/db_backup-'.date('YmdHi');
		}
		else	// remove the extension, to use the correct wrapper based on the extension
		{
			//echo '-> else<br>';	// !
			$name = preg_replace('/\.(bz2|gz)$/i','',$name);
		}
		$mode = $reading ? 'rb' : 'wb';
		list( , $type) = explode('.', basename($name));
		if($type == 'zip' && $reading && $this->backup_files)
		{
			//echo '-> $type == "zip" && $reading<br>';	// !
			if(!class_exists('ZipArchive', false))
			{
				$this->backup_files = false;
				//echo '   -> (new ZipArchive) == NULL<br>';	// !
				return lang("Cant open %1, needs ZipArchive", $name)."<br>\n";
			}
			if(!($f = fopen($name, $mode)))
			{
				//echo '   -> !($f = fopen($name, $mode))<br>';	// !
				$lang_mode = $reading ? lang("reading") : lang("writing");
				return lang("Cant open '%1' for %2", $name, $lang_mode)."<br>";
			}
			return $f;
		}
		if(class_exists('ZipArchive', false) && !$reading && $this->backup_files)
		{
			//echo '-> (new ZipArchive) != NULL && !$reading; '.$name.'<br>';	// !
			if(!($f = fopen($name, $mode)))
			{
				//echo '   -> !($f = fopen($name, $mode))<br>';	// !
				$lang_mode = $reading ? lang("reading") : lang("writing");
				return lang("Cant open '%1' for %2", $name, $lang_mode)."<br>";
			}
			return $f;
		}
		if(!($f = fopen("compress.bzip2://$name.bz2", $mode)) &&
	 		!($f = fopen("compress.zlib://$name.gz",$mode)) &&
 		 	!($f = fopen($name,$mode))
		)
		{
			//echo '-> !($f = fopen("compress.bzip2://$name.bz2", $mode))<br>';	// !
			$lang_mode = $reading ? lang("reading") : lang("writing");
			return lang("Cant open '%1' for %2", $name, $lang_mode)."<br>";
		}
		return $f;
	}

	/**
	 * Remove old backups, leaving at least
	 * backup_mincount backup files in place. Only backup files with
	 * the regular name scheme are taken into account.
	 *
	 * @param files_return Fills a given array of file names to display (if given).
	 */
	function housekeeping(&$files_return = false)
	{
		/* Stop housekeeping in case it is disabled. */
		if ($this->backup_mincount == 0)
		{
			return;
		}
		/* Search the backup directory for matching files. */
		$handle = @opendir($this->backup_dir);
		$files = array();
		while($handle && ($file = readdir($handle)))
		{
			/* Filter for only the files with the regular name (un-renamed).
			 * Leave special backup files (renamed) in place.
			 * Note that this also excludes "." and "..".
			 */
			if (preg_match("/^db_backup-[0-9]{12}(\.bz2|\.gz|\.zip|)$/",$file))
			{
				$files[filectime($this->backup_dir.'/'.$file)] = $file;
			}
		}
		if ($handle) closedir($handle);

		/* Sort the files by ctime. */
		krsort($files);
		$count = 0;
		foreach($files as $file)
		{
			if ($count >= $this->backup_mincount)//
			{
				$ret = unlink($this->backup_dir.'/'.$file);
				if (($ret) && (is_array($files_return)))
				{
					array_push($files_return, $file);
				}
			}
			$count ++;
		}
	}

	/**
	 * Save the housekeeping configuration in the database and update the local variables.
	 *
	 * @param int $minCount Minimum number of backups to keep.
	 * @param boolean $backupFiles include files in backup or not, default dont change!
	 */
	function saveConfig($minCount,$backupFiles=null)
	{
		Api\Config::save_value('backup_mincount',$this->backup_mincount=(int)$minCount,'phpgwapi');

		if (!is_null($backupFiles))
		{
			Api\Config::save_value('backup_files',$this->backup_files=(boolean)$backupFiles,'phpgwapi');
		}
	}

	/**
	 * Certain config settings NOT to restore (because they break a working system)
	 *
	 * @var array
	 */
	static $system_config = array(
		'files_dir',
		'temp_dir',
		'backup_dir',
		'backup_files',
		'webserver_url',
		'aspell_path',
		'hostname',
		'httpproxy_server',
		'httpproxy_port',
		'httpproxy_server_username',
		'httpproxy_server_password',
		'system_charset',
		'usecookies',
		'install_id',	// do not restore install_id, as that would give two systems with identical install_id
	);

	/**
	 * Backup all data in the form of a (compressed) csv file
	 *
	 * @param resource $f file opened with fopen for reading
	 * @param boolean $convert_to_system_charset =true obsolet, it's now allways done
	 * @param string $filename ='' gives the file name which is used in case of a zip archive.
	 * @param boolean $protect_system_config =true should above system_config values be protected (NOT overwritten)
	 * @param int $insert_n_rows =10 how many rows to insert in one sql statement
	 *
	 * @returns An empty string or an error message in case of failure.
	 */
	function restore($f,$convert_to_system_charset=true,$filename='',$protect_system_config=true, $insert_n_rows=10)
	{
		@set_time_limit(0);
		ini_set('auto_detect_line_endings',true);

		if (true) $convert_to_system_charset = true;	// enforce now utf-8 as system charset restores of old backups

		if ($protect_system_config)
		{
			$system_config = array();
			foreach($this->db->select(self::TABLE,'*',array(
				'config_app' => 'phpgwapi',
				'config_name' => self::$system_config,
			),__LINE__,__FILE__) as $row)
			{
				$system_config[] = $row;
			}
		}
		if (substr($this->db->Type,0,5) != 'mysql') $this->db->transaction_begin();

		// drop all existing tables
		foreach($this->adodb->MetaTables('TABLES') as $table)
		{
			if ($this->system_tables && preg_match($this->system_tables,$table) ||
				$this->egw_tables && !preg_match($this->egw_tables,$table))
			{
				 continue;
			}
			$this->schema_proc->DropTable($table);
		}
		// it could be an old backup
		list( , $type) = explode('.', basename($filename));
		$dir = $this->files_dir; // $GLOBALS['egw_info']['server']['files_dir'];
		// we may have to clean up old backup - left overs
		if (is_dir($dir.'/database_backup'))
		{
			self::remove_dir_content($dir.'/database_backup/');
			rmdir($dir.'/database_backup');
		}

		$list = array();
		$name = "";
		$zip = NULL;
		$_f = NULL;
		if($type == 'zip')
	    {
			// has already been verified to be available in fopen_backup
			$zip = new ZipArchive;
			if(($zip->open($filename)) !== TRUE)
			{
				return lang("Cant open '%1' for %2", $filename, lang("reading"))."<br>\n";
			}
			self::remove_dir_content($dir);  // removes the files-dir
			$zip->extractTo($dir);
			$_f = $f;
			$list = $this->get_file_list($dir.'/database_backup/');
			$name = $dir.'/database_backup/'.basename($list[0]);
			if(!($f = fopen($name, 'rb')))
			{
				return lang("Cant open '%1' for %2", $filename, lang("reading"))."<br>\n";
			}
		}
		// do not stop if for whatever reason some sql statement fails
		if ($this->db->Halt_On_Error != 'no')
		{
			$backup_db_halt_on_error = $this->db->Halt_On_Error;
			$this->db->Halt_On_Error = 'no';
		}
		$this->db_restore($f, $insert_n_rows);

		if ($convert_to_system_charset)	// store the changed charset
		{
			$this->db->insert(Api\Config::TABLE, array(
				'config_value' => $this->schema_proc->system_charset,
			),array(
				'config_app' => 'phpgwapi',
				'config_name' => 'system_charset',
			),__LINE__,__FILE__);
		}
		// restore protected system config
		if ($protect_system_config)
		{
			foreach($system_config as $row)
			{
				$this->db->insert(self::TABLE,array('config_value'=>$row['config_value']),array(
					'config_name' => $row['config_name'],
					'config_app'  => $row['config_app'],
				),__LINE__,__FILE__);
			}
			// check and reset cookie configuration, if it does not match current enviroment
			// if $_SERVER[HTTP_HOST] does not end with cookiedomain --> delete cookiedomain
			if (($cookiedomain = $this->db->select(self::TABLE,'config_value',array(
					'config_app' => 'phpgwapi',
					'config_name' => 'cookiedomain',
				),__LINE__,__FILE__)->fetchColumn()) && isset($_SERVER['HTTP_HOST']) &&
				(list($hostname) = explode(':',$_SERVER['HTTP_HOST'])) &&
				substr($hostname,-strlen($cookiedomain) !== $cookiedomain))
			{
				$this->db->delete(self::TABLE,array(
					'config_app' => 'phpgwapi',
					'config_name' => 'cookiedomain',
				),__LINE__,__FILE__);
			}
			// if configured webserver_url does NOT start with cookiepath --> delete cookiepath
			if (($cookiepath = $this->db->select(self::TABLE,'config_value',array(
					'config_app' => 'phpgwapi',
					'config_name' => 'cookiepath',
				),__LINE__,__FILE__)->fetchColumn()) &&
				substr(parse_url($system_config['webserver_url'], PHP_URL_PATH),0,strlen($cookiepath) !== $cookiepath))
			{
				$this->db->delete(self::TABLE,array(
					'config_app' => 'phpgwapi',
					'config_name' => 'cookiepath',
				),__LINE__,__FILE__);
			}
		}
		// restore original Halt_On_Error state (if changed)
		if ($backup_db_halt_on_error)
		{
			$this->db->Halt_On_Error = $backup_db_halt_on_error;
		}
		// zip?
		if($type == 'zip')
		{
			fclose($f);
			unlink($name);
			rmdir($dir.'/database_backup');
		}
		if (substr($this->db->Type,0,5) != 'mysql')
		{
			if (!$this->db->transaction_commit())
			{
				return lang('Restore failed');
			}
		}
		// generate an install_id if we dont have one (it breaks Api\Cache::flush() stalling the upgrade)
		unset($GLOBALS['egw_info']['server']['install_id']);
		if (!($GLOBALS['egw_info']['server']['install_id'] = Api\Cache::get_system_config('install_id', false)))
		{
			$GLOBALS['egw_info']['server']['install_id'] = md5(microtime(true).$_SERVER['HTTP_HOST']);
			$this->db->insert('egw_config', array(
				'config_value' => $GLOBALS['egw_info']['server']['install_id'],
			), array(
				'config_name' => 'install_id',
				'config_app'  => 'phpgwapi',
			), __LINE__, __FILE__);
		}
		// flush instance cache
		Api\Cache::flush(Api\Cache::INSTANCE);

		// search-and-register-hooks
		Api\Hooks::read(true);

		return '';
	}

	/**
	 * Restore data from a (compressed) csv file
	 *
	 * @param resource $f file opened with fopen for reading
	 * @param int|string $insert_n_rows =10 how many rows to insert in one sql statement, or string with column-name used as unique key for insert
	 * @returns int number of rows read from csv file
	 */
	function db_restore($f, $insert_n_rows=10)
	{
		$convert_to_system_charset = true;
		$table = null;
		$n = 0;
		$rows = array();
		while(!feof($f))
		{
			$line = trim(fgets($f)); ++$n;

			if (empty($line)) continue;

			if (substr($line,0,9) == 'version: ')
			{
				$api_version = trim(substr($line,9));
				continue;
			}
			if (substr($line,0,9) == 'charset: ')
			{
				$charset = trim(substr($line,9));
				// needed if mbstring.func_overload > 0, else eg. substr does not work with non ascii chars
				$ini_default_charset = version_compare(PHP_VERSION, '5.6', '<') ? 'mbstring.internal_encoding' : 'default_charset';
				@ini_set($ini_default_charset, $charset);

				// check if we really need to convert the charset, as it's not perfect and can do some damage
				if ($convert_to_system_charset && !strcasecmp($this->schema_proc->system_charset, $charset))
				{
					$convert_to_system_charset = false;	// no conversation necessary
				}
				// set the DB's client encoding (for mysql only if api_version >= 1.0.1.019)
				if ((!$convert_to_system_charset || $this->db->capabilities['client_encoding']) &&
					(substr($this->db->Type,0,5) != 'mysql' || !is_object($GLOBALS['egw_setup']) ||
					$api_version && !$GLOBALS['egw_setup']->alessthanb($api_version,'1.0.1.019')))
				{
					$this->db->Link_ID->SetCharSet($charset);
					if (!$convert_to_system_charset)
					{
						$this->schema_proc->system_charset = $charset;	// so schema_proc uses it for the creation of the tables
					}
				}
				continue;
			}
			if (substr($line,0,8) == 'schema: ')
			{
				// create the tables in the backup set
				$this->schemas = json_php_unserialize(trim(substr($line,8)));
				foreach($this->schemas as $table_name => $schema)
				{
					// if column is longtext in current schema, convert text to longtext, in case user already updated column
					foreach($schema['fd'] as $col => &$def)
					{
						if ($def['type'] == 'text' && $this->db->get_column_attribute($col, $table_name, true, 'type') == 'longtext')
						{
							$def['type'] = 'longtext';
						}
					}
					//echo "<pre>$table_name => ".self::write_array($schema,1)."</pre>\n";
					$this->schema_proc->CreateTable($table_name, $schema);
				}
				continue;
			}
			if (substr($line,0,7) == 'table: ')
			{
				if ($rows)	// flush pending rows of last table
				{
					$this->insert_multiple($table, $rows, $this->schemas[$table]);
				}
				$rows = array();
				$table = substr($line,7);
				if (!isset($this->schemas[$table])) $this->schemas[$table] = $this->db->get_table_definitions(true, $table);
				$auto_id = count($this->schemas[$table]['pk']) == 1 ? $this->schemas[$table]['pk'][0] : null;

				$cols = self::csv_split($line=fgets($f)); ++$n;
				$blobs = array();
				foreach($this->schemas[$table]['fd'] as $col => $data)
				{
					if ($data['type'] == 'blob') $blobs[] = $col;
				}
				// check if we have an old PostgreSQL backup useing 't'/'f' for bool values
				// --> convert them to MySQL and our new PostgreSQL format of 1/0
				$bools = array();
				foreach($this->schemas[$table]['fd'] as $col => $def)
				{
					if ($def['type'] === 'bool') $bools[] = $col;
				}
				if ($table == 'egw_cal_dates') error_log(__METHOD__."() $table: bools=".array2string($bools).", schema[fd]=".array2string($this->schemas[$table]['fd']));

				if (feof($f)) break;
				continue;
			}
			if ($convert_to_system_charset && !$this->db->capabilities['client_encoding'])
			{
				if ($GLOBALS['egw_setup'])
				{
					if (!is_object($GLOBALS['egw_setup']->translation->sql))
					{
						$GLOBALS['egw_setup']->translation->setup_translation_sql();
					}
				}
			}
			if ($table)	// do we already reached the data part
			{
				$import = true;
				$data = self::csv_split($line, $cols, $blobs, $bools);

				if ($table == 'egw_async' && in_array('##last-check-run##',$data))
				{
					//echo '<p>'.lang("Line %1: '%2'<br><b>csv data does contain ##last-check-run## of table %3 ==> ignored</b>",$n,$line,$table)."</p>\n";
					//echo 'data=<pre>'.print_r($data,true)."</pre>\n";
					$import = false;
				}
				if (in_array($table,$this->exclude_tables))
				{
					echo '<p><b>'.lang("Table %1 is excluded from backup and restore. Data will not be restored.",$table)."</b></p>\n";
					$import = false; // dont restore data of excluded tables
				}
				if ($import)
				{
					if (count($data) == count($cols))
					{
						if ($convert_to_system_charset && !$this->db->capabilities['client_encoding'])
						{
							$data = Api\Translation::convert($data,$charset);
						}
						if ($insert_n_rows > 1)
						{
							$rows[] = $data;
							if (count($rows) == $insert_n_rows)
							{
								$this->insert_multiple($table, $rows, $this->schemas[$table]);
								$rows = array();
							}
						}
						// update existing table using given unique key in $insert_n_rows (also removing auto-id/sequence)
						elseif(!is_numeric($insert_n_rows))
						{
							$where = array($insert_n_rows => $data[$insert_n_rows]);
							unset($data[$insert_n_rows]);
							if ($auto_id) unset($data[$auto_id]);
							$this->db->insert($table,$data,$where,__LINE__,__FILE__,false,false,$this->schemas[$table]);
						}
						else
						{
							try {
								$this->db->insert($table,$data,False,__LINE__,__FILE__,false,false,$this->schemas[$table]);
							}
							catch(Exception\InvalidSql $e) {
								echo "<p>".$e->getMessage()."</p>\n";
							}
						}
					}
					else
					{
						echo '<p>'.lang("Line %1: '%2'<br><b>csv data does not match column-count of table %3 ==> ignored</b>",$n,$line,$table)."</p>\n";
						echo 'data=<pre>'.print_r($data,true)."</pre>\n";
					}
				}
			}
		}
		if ($rows)	// flush pending rows
		{
			$this->insert_multiple($table, $rows, $this->schemas[$table]);
		}
		// updated the sequences, if the DB uses them
		foreach($this->schemas as $table => $schema)
		{
			foreach($schema['fd'] as $column => $definition)
			{
				if ($definition['type'] == 'auto')
				{
					$this->schema_proc->UpdateSequence($table,$column);
					break;	// max. one per table
				}
			}
		}

		// check if backup contained all indexes and create missing ones
		$this->schema_proc->CheckCreateIndexes();

		return $n;
	}

	/**
	 * Insert multiple rows ignoring doublicate entries
	 *
	 * @param string $table
	 * @param array $rows
	 * @param array $schema
	 */
	private function insert_multiple($table, array $rows, array $schema)
	{
		try {
			$this->db->insert($table, $rows, False, __LINE__, __FILE__, false, false, $schema);
		}
		catch(Exception\InvalidSql $e)
		{
			// try inserting them one by one, ignoring doublicates
			foreach($rows as $data)
			{
				try {
					$this->db->insert($table, $data, False, __LINE__, __FILE__, false, false, $schema);
				}
				catch(Exception\InvalidSql $e) {
					echo "<p>".$e->getMessage()."</p>\n";
				}
			}
		}
	}

	/**
	 * Removes a dir, no matter whether it is empty or full
	 *
	 * @param strin $dir
	 */
	private static function remove_dir_content($dir)
	{
		$list = scandir($dir);
		while($file = $list[0])
		{
			if(is_dir($file) && $file != '.' && $file != '..')
			    self::remove_dir_content($dir.'/'.$file);
			if(is_file($file) && $file != '.' && $file != '..')
			    unlink($dir.'/'.$file);
			array_shift($list);
		}
		//rmdir($dir);  // dont remove own dir
	}

	/**
	 * temp. replaces backslashes
	 */
	const BACKSLASH_TOKEN = '##!!**bAcKsLaSh**!!##';
	/**
	 * temp. replaces NULL
	 */
	const NULL_TOKEN = '##!!**NuLl**!!##';

	/**
	 * Split one line of a csv file into an array and does all unescaping
	 *
	 * @param string $line line to split
	 * @param array $keys =null keys to use or null to use numeric ones
	 * @param array $blobs =array() blob columns
	 * @param array $bools =array() bool columns, values might be 't'/'f' for old PostgreSQL backups
	 * @return array
	 */
	public static function csv_split($line, $keys=null, $blobs=array(), $bools=array())
	{
		if (function_exists('str_getcsv'))	// php5.3+
		{
			// we need to take care of literal "NULL" values, replacing them we a special token as str_getcsv removes enclosures around strings
			// str_getcsv uses '""' for '"' instead of '\\"' and does not unescape '\\n', '\\r' or '\\\\' (two backslashes)
			$fields = str_getcsv(strtr($line, array(
				'"NULL"' => self::NULL_TOKEN,
				'\\\\'   => self::BACKSLASH_TOKEN,
				'\\"'    => '""',
				'\\n'    => "\n",
				'\\r'    => "\r")), ',', '"', '\0');
			// replace NULL-token again with 'NULL', 'NULL' with null and BACKSLASH-token with a backslash
			foreach($fields as &$field)
			{
				switch($field)
				{
					case self::NULL_TOKEN:
						$field = 'NULL';
						break;
					case 'NULL':
						$field = null;
						break;
					default:
						$field = str_replace(self::BACKSLASH_TOKEN, '\\', $field);
						break;
				}
			}
			if ($keys)	// if string keys are to be used --> combine keys and values
			{
				$fields = array_combine($keys, $fields);
				// base64-decode blob columns, if they are base64 encoded
				foreach($blobs as $key)
				{
					if (!is_null($fields[$key]) && ($tmp = base64_decode($fields[$key], true)) !== false)
					{
						$fields[$key] = $tmp;
					}
				}
				// decode bool columns, they might be 't'/'f' for old PostgreSQL backups
				foreach($bools as $key)
				{
					$fields[$key] = Api\Db::from_bool($fields[$key]);
				}
			}
			return $fields;
		}
		// pre 5.3 implementation
		$fields = explode(',',trim($line));

		$str_pending = False;
		$n = 0;
		foreach($fields as $field)
		{
			if ($str_pending !== False)
			{
				$field = $str_pending.','.$field;
				$str_pending = False;
			}
			$key = $keys ? $keys[$n] : $n;

			if ($field[0] == '"')
			{
				if (substr($field,-1) !== '"' || $field === '"' || !preg_match('/[^\\\\]+(\\\\\\\\)*"$/',$field))
				{
					$str_pending = $field;
					continue;
				}
				$arr[$key] = str_replace(self::BACKSLASH_TOKEN,'\\',str_replace(array('\\\\','\\n','\\r','\\"'),array(self::BACKSLASH_TOKEN,"\n","\r",'"'),substr($field,1,-1)));
			}
			elseif ($keys && strlen($field) > 26)
			{
				$arr[$key] = base64_decode($field);
			}
			elseif (in_array($key, $bools))
			{
				$arr[$key] = Api\Db::from_bool($field);
			}
			else
			{
				$arr[$key] = $field == 'NULL' ? NULL : $field;
			}
			++$n;
		}
		return $arr;
	}

	/**
	 * escape data for csv
	 */
	public static function escape_data(&$data,$col,$defs)
	{
		if (is_null($data))
		{
			$data = 'NULL';
		}
		else
		{
			switch($defs[$col]['type'])
			{
				case 'int':
				case 'auto':
				case 'decimal':
				case 'date':
				case 'timestamp':
					break;
				case 'blob':
					$data = base64_encode($data);
					break;
				case 'bool':	// we use MySQL 0, 1 in csv, not PostgreSQL 't', 'f'
					$data = (int)Api\Db::from_bool($data);
					break;
				default:
					$data = '"'.str_replace(array('\\',"\n","\r",'"'),array('\\\\','\\n','\\r','\\"'),$data).'"';
					break;
			}
		}
	}

	/**
	 * Number of rows to select per chunk, to not run into memory limit on huge tables
	 */
	const ROW_CHUNK = 10000;

	/**
	 * Backup all data in the form of a (compressed) csv file
	 *
	 * @param resource $f file opened with fopen for writing
	 * @param boolean $lock_table =null true: allways, false: never, null: if no primary key
	 *	default of null limits memory usage if there is no primary key, by locking table and use ROW_CHUCK
	 * @todo use https://github.com/maennchen/ZipStream-PHP to not assemble all files in memmory
	 */
	function backup($f, $lock_table=null)
	{
		//echo "function backup($f)<br>";	// !
		@set_time_limit(0);
		$dir = $this->files_dir; // $GLOBALS['egw_info']['server']['files_dir'];
		// we may have to clean up old backup - left overs
		if (is_dir($dir.'/database_backup'))
		{
			self::remove_dir_content($dir.'/database_backup/');
			rmdir($dir.'/database_backup');
		}

		$file_list = array();
		$name = $this->backup_dir.'/db_backup-'.date('YmdHi');
		$filename = $name.'.zip';
		$zippresent = false;
		if(class_exists('ZipArchive') && $this->backup_files)
		{
			$zip = new ZipArchive;
			if(is_object($zip))
			{
				$zippresent = true;
				//echo '-> is_object($zip); '.$filename.'<br>';	// !
				$res = $zip->open($filename, ZipArchive::CREATE);
				if($res !== TRUE)
				{
					//echo '   -> !$res<br>';	// !
					return lang("Cant open '%1' for %2", $filename, lang("writing"))."<br>\n";
				}
				$file_list = $this->get_file_list($dir);
			}
		}
		fwrite($f,"EGroupware backup from ".date('Y-m-d H:i:s')."\n\n");

		fwrite($f,"version: $this->api_version\n\n");

		fwrite($f,"charset: $this->charset\n\n");

		$this->schema_backup($f);	// add the schema in a human readable form too

		fwrite($f,"\nschema: ".json_encode($this->schemas)."\n");

		foreach($this->schemas as $table => $schema)
		{
			if (in_array($table,$this->exclude_tables)) continue;	// dont backup

			// do we have a primary key?
			// --> use it to order and limit rows, to kope with rows being added during backup
			// otherwise new rows can cause rows being backed up twice and
			// backups don't restore because of doublicate keys
			$pk = $schema['pk'] && count($schema['pk']) == 1 ? $schema['pk'][0] : null;

			if ($lock_table || empty($pk) && is_null($lock_table))
			{
				$this->db->Link_ID->RowLock($table);
			}
			$total = $max = 0;
			do {
				$num_rows = 0;
				// querying only chunks for 10000 rows, to not run into memory limit on huge tables
				foreach($this->db->select($table, '*',
					// limit by maximum primary key already received
					empty($pk) || !$max ? false : $pk.' > '.$this->db->quote($max, $schema['fd'][$pk]['type']),
					__LINE__, __FILE__,
					// if no primary key, either lock table, or query all rows
					empty($pk) ? ($lock_table !== false ? $total : false) : 0,
					// if we have a primary key, order by it to ensure rows are not read double
					empty($pk) ? '' : 'ORDER BY '.$this->db->name_quote($pk).' ASC',
					false, self::ROW_CHUNK) as $row)
				{
					if (!empty($pk)) $max = $row[$pk];
					if ($total === 0) fwrite($f,"\ntable: $table\n".implode(',',array_keys($row))."\n");

					array_walk($row, array(__CLASS__, 'escape_data'), $schema['fd']);
					fwrite($f,implode(',',$row)."\n");
					++$total;
					++$num_rows;
				}
			}
			while((!empty($pk) || $lock_table !== false) && !($total % self::ROW_CHUNK) && $num_rows);

			if (!$pk) $this->db->Link_ID->RollbackLock($table);
		}
		if(!$zippresent)  // save without files
		{
			if ($this->backup_files)
			{
				echo '<center>'.lang("Cant open %1, needs ZipArchive", $name)."<br>\n".'</center>';
			}

		    fclose($f);
		    if (file_exists($name)) unlink($name);
			return TRUE;
		}
		// save files ....
		//echo $name.'<br>';
		$zip->addFile($name, 'database_backup/'.basename($name));
		$count = 1;
		foreach($file_list as $file)
		{
			//echo substr($file,strlen($dir)+1).'<br>';
			//echo $file.'<br>';
			$zip->addFile($file,substr($file,strlen($dir)+1));//,substr($file);
			if(($count++) == 100) { // the file descriptor limit
				$zip->close();
				if(($zip = new ZipArchive())) {
					$zip->open($filename);
					$count =0;
				}
			}
		}
		$zip->close();
		fclose($f);
		unlink($name);
		return true;
	}

	/**
	 * gets a list of all files on $f
	 *
	 * @param string file $f
	 * @param int $cnt =0
	 * @param string $path_name =''
	 *
	 * @return array (list of files)
	 */
	function get_file_list($f, $cnt = 0, $path_name = '')
	{
		//chdir($f);
		//echo "Processing $f <br>";
		if ($path_name =='') $path_name = $f;
		$tlist = scandir($f);
		$list = array();
		$i = $cnt;
		while($file = $tlist[0]) // remove all '.' and '..' and transfer to $list
		{
			if($file == '.' || $file == '..')
			{
				array_shift($tlist);
			}
			elseif ($file == 'debug.txt' && stripos($f,'activesync')!==false)
			{
				// skip activesync debug.txt on backupFiles
				//error_log(__METHOD__.__LINE__.'->'.$f.'/'.$file);
				array_shift($tlist);
			}
			else
			{
				if(is_dir($f.'/'.$file))
				{
					$nlist = $this->get_file_list($f.'/'.$file, $i);
					$list += $nlist;
					$i += count($nlist);
					array_shift($tlist);
				}
				else
				{
					$list[$i++] = $path_name.'/'.array_shift($tlist);
				}
			}
		}
		return $list;
	}

	/**
	 * Backup all schemas in the form of a setup/tables_current.inc.php file
	 *
	 * @param resource|boolean $f
	 */
	function schema_backup($f=False)
	{
		foreach($this->adodb->MetaTables('TABLES') as $table)
		{
			if ($this->system_tables && preg_match($this->system_tables,$table) ||
				$this->egw_tables && !preg_match($this->egw_tables,$table))
			{
				continue;
			}
			if ($this->db->Type == 'sapdb' || $this->db->Type == 'maxdb')
			{
				$table = strtolower($table);
			}
			if (!($this->schemas[$table] = $this->schema_proc->GetTableDefinition($table)))
			{
				unset($this->schemas[$table]);
			}
			if (($this->db->Type == 'sapdb' || $this->db->Type == 'maxdb') && $table == 'phpgw_anglemail')
			{
				// sapdb does not differ between text and blob
				$this->schemas[$table]['fd']['content']['type'] = 'blob';
			}
		}
		$def = "\t\$phpgw_baseline = ";
		$def .= self::write_array($this->schemas,1);
		$def .= ";\n";

		if ($f)
		{
			fwrite($f,$def);
		}
		else
		{
			$def = "<?php\n\t/* EGroupware schema-backup from ".date('Y-m-d H:i:s')." */\n\n".$def;
			Api\Header\Content::type('schema-backup-'.date('YmdHi').'.inc.php','text/plain',bytes($def));
			echo $def;
		}
	}

	/**
	 * Dump an array as php source
	 *
	 * copied from etemplate/inc/class.db_tools.inc.php
	 */
	private static function write_array($arr,$depth,$parent='')
	{
		if (in_array($parent,array('pk','fk','ix','uc')))
		{
			$depth = 0;
		}
		if ($depth)
		{
			$tabs = "\n";
			for ($n = 0; $n < $depth; ++$n)
			{
				$tabs .= "\t";
			}
			++$depth;
		}
		$def = "array($tabs".($tabs ? "\t" : '');

		$n = 0;
		foreach($arr as $key => $val)
		{
			if (!is_int($key))
			{
				$def .= "'$key' => ";
			}
			if (is_array($val))
			{
				$def .= self::write_array($val,$parent == 'fd' ? 0 : $depth,$key);
			}
			else
			{
				if ($key === 'nullable')
				{
					$def .= $val ? 'True' : 'False';
				}
				else
				{
					$def .= "'$val'";
				}
			}
			if ($n < count($arr)-1)
			{
				$def .= ",$tabs".($tabs ? "\t" : '');
			}
			++$n;
		}
		$def .= "$tabs)";

		return $def;
	}
}

/*
$line = '"de","NULL","ranking",NULL,NULL,"one backslash: \\\\ here","\\\\","use \\"yes\\", or \\"no, prefession\\"","benützen Sie \\"yes\\" oder \\"no, Beruf\\"",NULL';

echo "<p>line='$line'</p>\n";
$fields = Backup::csv_split($line);
echo "<pre>".print_r($fields,true)."</pre>\n";
//echo count($fields)." fields\n";
*/
