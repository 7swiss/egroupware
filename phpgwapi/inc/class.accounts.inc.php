<?php
	/**************************************************************************\
	* eGroupWare API - Accounts manager shared functions                       *
	* Written by Joseph Engo <jengo@phpgroupware.org>                          *
	*        and Bettina Gille [ceb@phpgroupware.org]                          *
	* shared functions for other account repository managers                   *
	* Copyright (C) 2000 - 2002 Joseph Engo                                    *
	* Copyright (C) 2003 Joseph Engo, Bettina Gille                            *
	* Caching and documentation added by RalfBecker-AT-outdoor-training.de     *
	* -------------------------------------------------------------------------*
	* This library is part of the eGroupWare API                               *
	* http://www.egroupware.org                                                * 
	* ------------------------------------------------------------------------ *
	* This library is free software; you can redistribute it and/or modify it  *
	* under the terms of the GNU Lesser General Public License as published by *
	* the Free Software Foundation; either version 2.1 of the License,         *
	* or any later version.                                                    *
	* This library is distributed in the hope that it will be useful, but      *
	* WITHOUT ANY WARRANTY; without even the implied warranty of               *
	* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.                     *
	* See the GNU Lesser General Public License for more details.              *
	* You should have received a copy of the GNU Lesser General Public License *
	* along with this library; if not, write to the Free Software Foundation,  *
	* Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA            *
	\**************************************************************************/

	/* $Id$ */

	if (empty($GLOBALS['egw_info']['server']['account_repository']))
	{
		if (!empty($GLOBALS['egw_info']['server']['auth_type']))
		{
			$GLOBALS['egw_info']['server']['account_repository'] = $GLOBALS['egw_info']['server']['auth_type'];
		}
		else
		{
			$GLOBALS['egw_info']['server']['account_repository'] = 'sql';
		}
	}
	include_once(EGW_API_INC . '/class.accounts_' . $GLOBALS['egw_info']['server']['account_repository'] . '.inc.php');

	/*
		Dont know where to put this (seek3r)
		This is where it belongs (jengo)
		This is where it ended up (milosch)
		Moved again at least temporarily since sql and ldap use it.
	*/
	$GLOBALS['egw_info']['server']['global_denied_users'] = array(
		'root'     => True, 'bin'      => True, 'daemon'   => True,
		'adm'      => True, 'lp'       => True, 'sync'     => True,
		'shutdown' => True, 'halt'     => True, 'ldap'     => True,
		'mail'     => True, 'news'     => True, 'uucp'     => True,
		'operator' => True, 'games'    => True, 'gopher'   => True,
		'nobody'   => True, 'xfs'      => True, 'pgsql'    => True,
		'mysql'    => True, 'postgres' => True, 'oracle'   => True,
		'ftp'      => True, 'gdm'      => True, 'named'    => True,
		'alias'    => True, 'web'      => True, 'sweep'    => True,
		'cvs'      => True, 'qmaild'   => True, 'qmaill'   => True,
		'qmaillog' => True, 'qmailp'   => True, 'qmailq'   => True,
		'qmailr'   => True, 'qmails'   => True, 'rpc'      => True,
		'rpcuser'  => True, 'amanda'   => True, 'apache'   => True,
		'pvm'      => True, 'squid'    => True, 'ident'    => True,
		'nscd'     => True, 'mailnull' => True, 'cyrus'    => True,
		'backup'    => True
	);

	$GLOBALS['egw_info']['server']['global_denied_groups'] = array(
		'root'      => True, 'bin'       => True, 'daemon'    => True,
		'sys'       => True, 'adm'       => True, 'tty'       => True,
		'disk'      => True, 'lp'        => True, 'mem'       => True,
		'kmem'      => True, 'wheel'     => True, 'mail'      => True,
		'uucp'      => True, 'man'       => True, 'games'     => True,
		'dip'       => True, 'ftp'       => True, 'nobody'    => True,
		'floppy'    => True, 'xfs'       => True, 'console'   => True,
		'utmp'      => True, 'pppusers'  => True, 'popusers'  => True,
		'slipusers' => True, 'slocate'   => True, 'mysql'     => True,
		'dnstools'  => True, 'web'       => True, 'named'     => True,
		'dba'       => True, 'oinstall'  => True, 'oracle'    => True,
		'gdm'       => True, 'sweep'     => True, 'cvs'       => True,
		'postgres'  => True, 'qmail'     => True, 'nofiles'   => True,
		'ldap'      => True, 'backup'    => True
	);

	/**
	 * Class for handling user and group accounts
	 *
	 * The class can be instanciated for a certain account, if no account is specified the user of the session is used.
	 * Some functions operate on that user!
	 */
	class accounts extends accounts_
	{
		var $memberships    = array();
		var $members        = array();
		var $xmlrpc_methods = array();
		// enables the session-cache
		var $use_session_cache = True;

		/**
		 * Standard constructor for setting $this->account_id
		 *
		 * This constructor sets the account id, if string is sent, converts to id
		 *
		 * @param int/string $account_id account to instanciate the class for, default user of the session
		 * @param string $account_type type ('u' or 'g') to set $this->account_type if given
		 */
		function accounts($account_id = '', $account_type='')
		{
			// enable the caching in the session onyl for ldap
			$this->use_session_cache = $GLOBALS['egw_info']['server']['account_repository'] == 'ldap';

			if (is_object($GLOBALS['egw_setup']->db))
			{
				$this->db = clone($GLOBALS['egw_setup']->db);
			}
			else
			{
				$this->db = clone($GLOBALS['egw']->db);
			}

			if($account_id != '')
			{
				$this->account_id = get_account_id($account_id);
			}

			if($account_type != '')
			{
				$this->account_type = $account_type;
			}

			$this->query_types = array(
				'all' => 'all fields',
				'firstname' => 'firstname',
				'lastname' => 'lastname',
				'lid' => 'LoginID',
				'email' => 'email',	
				'start' => 'start with',
				'exact' => 'exact',
			);
			$this->accounts_();			// call constructor of extended class

			$this->xmlrpc_methods[] = array(
				'name'        => 'get_list',
				'description' => 'Returns a list of accounts and/or groups'
			);
			$this->xmlrpc_methods[] = array(
				'name'        => 'name2id',
				'description' => 'Cross reference account_lid with account_id'
			);
			$this->xmlrpc_methods[] = array(
				'name'        => 'id2name',
				'description' => 'Cross reference account_id with account_lid'
			);
		}

		/**
		 * Sets up the account-data cache
		 *
		 * The cache is shared between all instances of the account-class and it can be save in the session,
		 * if use_session_cache is set to True
		 */
		function setup_cache()
		{
			if ($this->use_session_cache &&		// are we supposed to use a session-cache
				!@$GLOBALS['egw_info']['accounts']['session_cache_setup'] &&	// is it already setup
				// is the account-class ready (startup !)
				is_object($GLOBALS['egw']->session) && $GLOBALS['egw']->session->account_id)
			{
				// setting up the session-cache
				$GLOBALS['egw_info']['accounts']['cache'] = $GLOBALS['egw']->session->appsession('accounts_cache','phpgwapi');
				$GLOBALS['egw_info']['accounts']['session_cache_setup'] = True;
				//echo "accounts::setup_cache() cache=<pre>".print_r($GLOBALS['egw_info']['accounts']['cache'],True)."</pre>\n";
			}
			if (!isset($this->cache))
			{
				$this->cache = &$GLOBALS['egw_info']['accounts']['cache'];
			}
			if (!is_array($this->cache)) $this->cache = array();
		}

		/**
		 * Saves the account-data cache in the session
		 *
		 * Gets called from common::phpgw_final()
		 */
		function save_session_cache()
		{
			if ($this->use_session_cache &&		// are we supposed to use a session-cache
				$GLOBALS['egw_info']['accounts']['session_cache_setup'] &&	// is it already setup
				// is the account-class ready (startup !)
				is_object($GLOBALS['egw']->session))
			{
				$GLOBALS['egw']->session->appsession('accounts_cache','phpgwapi',$GLOBALS['egw_info']['accounts']['cache']);
			}
		}

		/**
		 * Searches / lists accounts: users and/or groups
		 *
		 * @param array with the following keys:
		 * @param $param['type'] string/int 'accounts', 'groups', 'owngroups' (groups the user is a member of), 'both'
		 *	or integer group-id for a list of members of that group
		 * @param $param['start'] int first account to return (returns offset or max_matches entries) or all if not set
		 * @param $param['sort'] string column to sort after, default account_lid if unset
		 * @param $param['order'] string 'ASC' or 'DESC', default 'DESC' if not set
		 * @param $param['query'] string to search for, no search if unset or empty
		 * @param $param['query_type'] string:
		 *	'all'   - query all fields for containing $param[query]
		 *	'start' - query all fields starting with $param[query]
		 *	'exact' - query all fields for exact $param[query]
		 *	'lid','firstname','lastname','email' - query only the given field for containing $param[query]
		 * @param $param['app'] string with an app-name, to limit result on accounts with run-right for that app
		 * @param $param['offset'] int - number of matches to return if start given, default use the value in the prefs
		 * @return array with uid / data pairs, data is an array with account_id, account_lid, account_firstname,
		 *	account_lastname, person_id (id of the linked addressbook entry), account_status, account_expires, account_primary_group
		 */
		function search($param)
		{
			//echo "<p>accounts::search(".print_r($param,True).")</p>\n";
			$this->setup_cache();
			$account_search = &$this->cache['account_search'];
			
			$serial = serialize($param);

			if (isset($account_search[$serial]))
			{
				$this->total = $account_search[$serial]['total'];
			}
			elseif (function_exists('accounts_::search'))	// implements its on search function ==> use it
			{
				$account_search[$serial]['data'] = accounts_::search($param);
				$account_search[$serial]['total'] = $this->total;
			}
			else
			{
				$serial2 = $serial;
				if (is_numeric($param['type']) || $param['app'] || $param['type'] == 'owngroups')	// do we need to limit the search on a group or app?
				{
					$app = $param['app'];
					unset($param['app']);
					if (is_numeric($param['type']))
					{
						$group = (int) $param['type'];
						$param['type'] = 'accounts';
					}
					elseif ($param['type'] == 'owngroups')
					{
						$group = true;
						$param['type'] = 'groups';
					}
					$start = $param['start'];
					unset($param['start']);
					$serial2 = serialize($param);
				}
				if (!isset($account_search[$serial2]))	// check if we already did this general search
				{
					$account_search[$serial2]['data'] = array();
					$accounts = accounts_::get_list($param['type'],$param['start'],$param['sort'],$param['order'],$param['query'],$param['offset'],$param['query_type']);
					if (!$accounts) $accounts = array();
					foreach($accounts as $data)
					{
						$account_search[$serial2]['data'][$data['account_id']] = $data;
					}
					$account_search[$serial2]['total'] = $this->total;
				}
				else
				{
					$this->total = $account_search[$serial2]['total'];
				}
				//echo "accounts_::get_list($param[type],$param[start],$param[sort],$param[order],$param[query],$param[offset],$param[query_type]) returned<pre>".print_r($account_search[$serial2],True)."</pre>\n";
				if ($app || $group)	// limit the search on accounts with run-rights for app or a group
				{
					$valid = array();
					if ($app)
					{
						$valid = $this->split_accounts($app,$param['type'] == 'both' ? 'merge' : $param['type']);
					}
					if ($group)
					{
						$members = is_int($group) ? $GLOBALS['egw']->acl->get_ids_for_location($group, 1, 'phpgw_group') :
							$GLOBALS['egw']->acl->get_location_list_for_id('phpgw_group', 1,$GLOBALS['egw_info']['user']['account_id']);
						if (!$members) $members = array();
						$valid = !$app ? $members : array_intersect($valid,$members);	// use the intersection
					}
					//echo "<p>limiting result to app='app' and/or group=$group valid-ids=".print_r($valid,true)."</p>\n";
					$offset = $param['offset'] ? $param['offset'] : $GLOBALS['egw_info']['user']['preferences']['common']['maxmatchs'];
					$stop = $start + $offset;
					$n = 0;
					$account_search[$serial]['data'] = array();
					foreach ($account_search[$serial2]['data'] as $id => $data)
					{
						if (!in_array($id,$valid))
						{
							$this->total--;
							continue;
						}
						// now we have a valid entry
						if (!is_int($start) || $start <= $n && $n < $stop)
						{
							$account_search[$serial]['data'][$id] = $data;
						}
						$n++;
					}
					$account_search[$serial]['total'] = $this->total;
				}
			}
			//echo "<p>accounts::search('$serial')=<pre>".print_r($account_search[$serial]['data'],True).")</pre>\n";
			return $account_search[$serial]['data'];
		}

		/**
		 * Searches / lists accounts: users and/or groups
		 *
		 * @deprecated use search
		 */
		function get_list($_type='both',$start = '',$sort = '', $order = '', $query = '', $offset = '',$query_type='')
		{
			//echo "<p>accounts::get_list(".print_r($_type,True).",start='$start',sort='$sort',order='$order',query='$query',offset='$offset')</p>\n";
			$this->setup_cache();
			$account_list = &$this->cache['account_list'];

			// For XML-RPC
			if (is_array($_type))
			{
				$p      = $_type;
				$_type  = $p['type'];
				$start  = $p['start'];
				$order  = $p['order'];
				$query  = $p['query'];
				$offset = $p['offset'];
				$query_type = $p['query_type'];
			}
			else
			{
				$p = array(
					'type' => $_type,
					'start' => $start,
					'order' => $order,
					'query' => $query,
					'offset' => $offset,
					'query_type' => $query_type ,
				);
			}
			$serial = serialize($p);

			if (isset($account_list[$serial]))
			{
				$this->total = $account_list[$serial]['total'];
			}
			else
			{
				$account_list[$serial]['data'] = accounts_::get_list($_type,$start,$sort,$order,$query,$offset,$query_type);
				$account_list[$serial]['total'] = $this->total;
			}
			return $account_list[$serial]['data'];
		}

		/**
		 * test if the account this class is instanciated for is expired
		 *
		 * @return boolean true=expired (no more login possible), false otherwise
		 */
		function is_expired()
		{
			if ($this->data['expires'] != -1 && $this->data['expires'] < time())
			{
				return True;
			}
			else
			{
				return False;
			}
		}

		/**
		 * Invalidate the cache (or parts of it) after change in $account_id
		 *
		 * Atm simplest approach - delete it all ;-)
		 */
		function cache_invalidate($account_id)
		{
			//echo "<p>accounts::cache_invalidate($account_id)</p>\n";
			$GLOBALS['egw_info']['accounts']['cache'] = array();
			
			if (method_exists($GLOBALS['egw'],'invalidate_session_cache'))	// egw object in setup is limited
			{
				$GLOBALS['egw']->invalidate_session_cache();	// invalidates whole egw-enviroment if stored in the session
			}
		}

		/**
		 * saves the account-data in the internal data-structure of this class to the repository
		 */
		function save_repository()
		{
			$this->cache_invalidate($this->account_id);
			accounts_::save_repository();
		}

		/**
		 * Deletes the account spezified by $accountid, deletes also all acl-entries for that account
		 *
		 * @param int $accountid numeric account-id
		 */
		function delete($accountid)
		{
			$this->cache_invalidate($accountid);
			accounts_::delete($accountid);
			
			// delete all acl_entries belonging to that user or group
			$GLOBALS['egw']->acl->delete_account($accountid);
		}

		/**
		 * Create a new account with the given $account_info
		 * 
		 * @param array data for the new account
		 * @return int new nummeric account-id
		 */
		function create($account_info,$default_prefs=True)
		{
			$account_id = accounts_::create($account_info,$default_prefs);
			$this->cache_invalidate($account_id);

			return $account_id;
		}

		/**
		 * Reads the data of the account this class is instanciated for
		 *
		 * @return array with the internal data
		 */
		function read_repository()
		{
			$this->setup_cache();
			$account_data = &$this->cache['account_data'];

			if (isset($account_data[$this->account_id]))
			{
				return $this->data = $account_data[$this->account_id];
			}
			return $account_data[$this->account_id] = accounts_::read_repository();
		}

		/**
		 * Return data of the account this class is instanciated for
		 *
		 * only calls read_repository if no data read so far
		 *
		 * @return array with the internal data
		 */
		function read()
		{
			if (count($this->data) == 0)
			{
				$this->read_repository();
			}

			reset($this->data);
			return $this->data;
		}

		/**
		 * copies the given $data into the internal array
		 *
		 * @param array with data
		 * @return array $this->data = $data
		 */
		function update_data($data)
		{
			reset($data);
			$this->data = Array();
			$this->data = $data;

			reset($this->data);
			return $this->data;
		}

		/**
		 * Get all memberships of an account $accountid / groups the account is a member off
		 *
		 * @param int/string $accountid='' numeric account-id or alphanum. account-lid, 
		 *	default account of the user of this session
		 * @return array or arrays with keys 'account_id' and 'account_name' for the groups $accountid is a member of
		 */
		function membership($accountid = '')
		{
			$this->setup_cache();
			$membership_list = &$this->cache['membership_list'];

			$account_id = get_account_id($accountid);

			if (isset($membership_list[$account_id]))
			{
				return $membership_list[$account_id];
			}

			if(!($gids = $GLOBALS['egw']->acl->get_location_list_for_id('phpgw_group', 1, $account_id)))
			{
				return $membership_list[$account_id] = False;
			}

			$memberships = Array();
			foreach($gids as $gid)
			{
				$memberships[] = Array('account_id' => $gid, 'account_name' => $this->id2name($gid));
			}
			return $membership_list[$account_id] = $memberships;
		}

		/**
		 * Get all members of the group $accountid
		 *
		 * @param int/string $accountid='' numeric account-id or alphanum. account-lid, 
		 *	default account of the user of this session
		 * @return array or arrays with keys 'account_id' and 'account_name'
		 */
		function member($accountid = '')
		{
			$this->setup_cache();
			$member_list = &$this->cache['member_list'];

			$account_id = get_account_id($accountid);

			if (isset($member_list[$account_id]))
			{
				return $member_list[$account_id];
			}

			if (!($uids = $GLOBALS['egw']->acl->get_ids_for_location($account_id, 1, 'phpgw_group')))
			{
				return $member_list[$account_id] = False;
			}

			$members = array();
			foreach ($uids as $uid)
			{
				$members[] = Array('account_id' => $uid, 'account_name' => $this->id2name($uid));
			}
			return $member_list[$account_id] = $members;
		}

		/**
		 * Using the common functions next_id and last_id, find the next available account_id
		 *
		 * NOTE: to my knowledge this is not used any more RalfBecker 2004/06/15
		 *
		 * @deprecated 
		 * @param $string $account_type='u' (optional, default to 'u')
		 */
		function get_nextid($account_type='u')
		{
			$min = $GLOBALS['egw_info']['server']['account_min_id'] ? $GLOBALS['egw_info']['server']['account_min_id'] : 0;
			$max = $GLOBALS['egw_info']['server']['account_max_id'] ? $GLOBALS['egw_info']['server']['account_max_id'] : 0;

			if ($account_type == 'g')
			{
				$type = 'groups';
			}
			else
			{
				$type = 'accounts';
			}
			$nextid = (int)$GLOBALS['egw']->common->last_id($type,$min,$max);

			/* Loop until we find a free id */
			$free = 0;
			while (!$free)
			{
				$account_lid = '';
				//echo '<br>calling search for id: '.$nextid;
				if ($this->exists($nextid))
				{
					$nextid = (int)$GLOBALS['egw']->common->next_id($type,$min,$max);
				}
				else
				{
					$account_lid = $this->id2name($nextid);
					/* echo '<br>calling search for lid: '.$account_lid . '(from account_id=' . $nextid . ')'; */
					if ($this->exists($account_lid))
					{
						$nextid = (int)$GLOBALS['egw']->common->next_id($type,$min,$max);
					}
					else
					{
						$free = True;
					}
				}
			}
			if	($GLOBALS['egw_info']['server']['account_max_id'] &&
				($nextid > $GLOBALS['egw_info']['server']['account_max_id']))
			{
				return False;
			}
			/* echo '<br>using'.$nextid;exit; */
			return $nextid;
		}

		/**
		 * splits users and groups from a array of id's or the accounts with run-rights for a given app-name
		 *
		 * @param array $app_users array of user-id's or app-name (if you use app-name the result gets cached!)
		 * @param string $use what should be returned only an array with id's of either 'accounts' or 'groups'.
		 *	Or an array with arrays for 'both' under the keys 'groups' and 'accounts' or 'merge' for accounts
		 *	and groups merged into one array
		 * @return array/boolean see $use, false on error (wront $use)
		 */
		function split_accounts($app_users,$use='both')
		{
			if (!is_array($app_users))
			{
				$this->setup_cache();
				$cache = &$this->cache['account_split'][$app_user];

				if (is_array($cache))
				{
					return $cache;
				}
				$app_users = $GLOBALS['egw']->acl->get_ids_for_location('run',1,$app_users);
			}
			$accounts = array(
				'accounts' => array(),
				'groups' => array(),
			);
			foreach($app_users as $id)
			{
				$type = $this->get_type($id);
				if($type == 'g')
				{
					$accounts['groups'][$id] = $id;
					foreach((array)$GLOBALS['egw']->acl->get_ids_for_location($id,1,'phpgw_group') as $id)
					{
						$accounts['accounts'][$id] = $id;
					}
				}
				else
				{
					$accounts['accounts'][$id] = $id;
				}
			}

			// not sure why they need to be sorted, but we need to remove the keys anyway
			sort($accounts['groups']);
			sort($accounts['accounts']);

			if (isset($cache))
			{
				$cache = $accounts;
			}
			//echo "<p>accounts::split_accounts(".print_r($app_users,True).",'$use') = <pre>".print_r($accounts,True)."</pre>\n";

			switch($use)
			{
				case 'both':
					return $accounts;
				case 'groups':
					return $accounts['groups'];
				case 'accounts':
					return $accounts['accounts'];
				case 'merge':
					return array_merge($accounts['accounts'],$accounts['groups']);
			}
			return False;
		}

		/**
		 * phpgw compatibility function, better use split_accounts
		 *
		 * @deprecated  use split_accounts
		 */
		function return_members($accounts)
		{
			$arr = $this->split_accounts($accounts);

			return array(
				'users'  => $arr['accounts'],
				'groups' => $arr['groups'],
			);
		}

		/**
		 * convert an alphanumeric account-value (account_lid, account_email) to the account_id
		 *
		 * @param string $name value to convert
		 * @param string $which='account_lid' type of $name: account_lid (default), account_email
		 * @return int/false numeric account_id or false on error ($name not found)
		 */
		function name2id($name,$which='account_lid')
		{
			$this->setup_cache();
			$name_list = &$this->cache['name_list'];

			if(@isset($name_list[$which][$name]) && $name_list[$which][$name])
			{
				return $name_list[$which][$name];
			}

			/* Don't bother searching for empty account_lid */
			if(empty($name))
			{
				return False;
			}
			return $name_list[$which][$name] = accounts_::name2id($name,$which);
		}

		/**
		 * convert an numeric account_id to any other value of that account (account_lid, account_email, ...)
		 *
		 * @param int $account_id numerica account_id
		 * @param string $which='account_lid' type to convert to: account_lid (default), account_email, ...
		 * @return string/false converted value or false on error ($account_id not found)
		 */
		function id2name($account_id,$which='account_lid')
		{
			$this->setup_cache();
			$id_list = &$this->cache['id_list'];

			if (! $account_id)
			{
				return False;
			}

			if($id_list[$account_id][$which])
			{
				return $id_list[$account_id][$which];
			}
			return $id_list[$account_id][$which] = accounts_::id2name($account_id,$which);
		}

		/**
		 * get the type of an account: 'u' = user, 'g' = group
		 *
		 * @param int/string $accountid numeric account-id or alphanum. account-lid, 
		 *	if !$accountid account of the user of this session
		 * @return string/false 'u' = user, 'g' = group or false on error ($accountid not found)
		 */
		function get_type($accountid)
		{
			$account_id = get_account_id($accountid);
			
			return $account_id > 0 ? 'u' : ($account_id < 0 ? 'g' : false);
		}

		/**
		 * Gets account-name (lid), firstname and lastname of an account $accountid
		 *
		 * @param int/string $accountid='' numeric account-id or alphanum. account-lid, 
		 *	if !$accountid account of the user of this session
		 * @param string &$lid on return: alphanumeric account-name (lid)
		 * @param string &$fname on return: first name
		 * @param string &$lname on return: last name
		 * @return boolean true if $accountid was found, false otherwise
		 */	 
		function get_account_name($accountid,&$lid,&$fname,&$lname)
		{
			$this->setup_cache();
			$account_name = &$this->cache['account_name'];

			$account_id = get_account_id($accountid);
			if(isset($account_name[$account_id]))
			{
				$lid = $account_name[$account_id]['lid'];
				$fname = $account_name[$account_id]['fname'];
				$lname = $account_name[$account_id]['lname'];
				return $account_name[$account_id] !== False;
			}
			$Ok = accounts_::get_account_name($accountid,$lid,$fname,$lname);
			
			if (empty($fname)) $fname = $lid;
			if (empty($lname)) $lname = $this->get_type($accountid) == 'g' ? lang('Group') : lang('user');

			$account_name[$account_id] = array(
				'lid' => $lid,
				'fname' => $fname,
				'lname' => $lname,
			);
			return $Ok;
		}

		/**
		 * Reads account-data for a given $account_id from the repository AND sets the class-vars with it
		 *
		 * Same effect as instanciating the class with that account, dont do it with $GLOBALS['egw']->account !!!
		 *
		 * @param int $accountid numeric account-id 
		 * @return array with keys lid, firstname, lastname, fullname, type
		 */
		function get_account_data($account_id)
		{
			$this->account_id = $account_id;
			$this->read_repository();

			$data[$this->data['account_id']]['lid']       = $this->data['account_lid'];
			$data[$this->data['account_id']]['firstname'] = $this->data['firstname'];
			$data[$this->data['account_id']]['lastname']  = $this->data['lastname'];
			$data[$this->data['account_id']]['fullname']  = $this->data['fullname'];
			$data[$this->data['account_id']]['type']      = $this->data['account_type'];

			return $data;
		}
	}
