<?php

/**
 * EGroupware - Calendar's category report utility
 *
 * @link http://www.egroupware.org
 * @package calendar
 * @author Hadi Nategh <hn-AT-egroupware.org>
 * @copyright (c) 2013-16 by Hadi Nategh <hn-AT-egroupware.org>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

use EGroupware\Api;
use EGroupware\Api\Link;
use EGroupware\Api\Framework;
use EGroupware\Api\Egw;
use EGroupware\Api\Etemplate;

/**
 * This class reports amount of events taken by users based
 * on categories. The report would be based on start and end
 * date and can be specified with several options for each
 * category. The result of the report would be a CSV file
 * consistent of user full name, categories names, and amount
 * of time (hour|minute|second).
 *
 */
class calendar_category_report extends calendar_ui{

	/**
	 * Public functions allowed to get called
	 *
	 * @var type
	 */
	var $public_functions = array(
		'index' => True,
	);

	/**
	 * Constructor
	 */
	function __construct()
	{
		parent::__construct(true);	// call the parent's constructor
		$this->tmpl = new Api\Etemplate('calendar.category_report');
	}

	/**
	 * Function to check if the given date is a weekend date
	 *
	 * @param int $date timestamp as date
	 *
	 * @return boolean returns true if the date is weekend
	 */
	public static function isWeekend($date)
	{
		return (date('N',$date) >= 6);
	}

	/**
	 * Function to check if the given date is a holiday date
	 *
	 * @param int $date timestamp as date
	 *
	 * @return boolean returns true if the date is holiday
	 */
	public function isHoliday($date)
	{
		return array_key_exists(date('Ymd', $date), $this->bo->read_holidays(date('Y', $date)));
	}

	/**
	 * This function processes given day array and select eligible events
	 *
	 * @param array $week_sum array to keep tracking of weeks
	 * @param array $day array to keep tracking of eligible events of the day
	 * @param array $events events of the day
	 * @param int $cat_id category id
	 * @param int $holidays holiday option
	 * @param int $weekend weekend option
	 * @param int $min_days min_days option
	 * @param int $unit unit option
	 *
	 */
	public function process_days(&$week_sum, &$day, $events, $cat_id, $holidays, $weekend, $min_days, $unit)
	{
		foreach ($events as &$event)
		{
			$categories = explode(',', $event['category']);
			if (!in_array($cat_id, $categories) || ($weekend && self::isWeekend($event['start'])) || (!$holidays && $this->isHoliday($event['start']))) continue;
			$day[$event['owner']][$cat_id][$event['id']] = array (
				'weekN' => date('W', $event['start']),
				'cat_id' => $cat_id,
				'event_id' => $event['id'],
				'amount' => $event['end'] - $event['start'],
				'min_days' => $min_days,
				'unit' => $unit
			);

			if ($min_days)
			{
				$week_sum[$event['owner']][date('W', $event['start'])][$cat_id][$event['id']][] = $event['end'] - $event['start'];
				$week_sum[$event['owner']][date('W', $event['start'])][$cat_id]['min_days'] = $min_days;
			}
		}
	}

	/**
	 * function to add up days
	 *
	 * @param array $days array of days
	 * @return int returns sum of amount of events
	 */
	public static function add_days ($days) {
		$sum = 0;
		foreach ($days as $val)
		{
			$sum = $sum + $val;
		}
		return $sum;
	}

	/**
	 * Function to build holiday report index interface and logic
	 *
	 * @param type $content
	 */
	public function index ($content = null)
	{
		$api_cats = new Api\Categories($GLOBALS['egw_info']['user']['account_id'],'calendar');
		if (is_null($content))
		{
			$cats = $api_cats->return_sorted_array($start=0, false, '', 'ASC', 'cat_name', true, 0, true);
			$cats_status = $GLOBALS['egw_info']['user']['preferences']['calendar']['category_report'];
			foreach ($cats as &$value)
			{
				$content['grid'][] = array(
					'cat_id' => $value['id'],
					'user' => '',
					'weekend' => '',
					'holidays' => '',
					'min_days' => 0,
					'unit' => 3600,
					'enable' => true
				);
			}
			if (is_array($cats_status))
			{
				$content['grid'] = array_replace_recursive($content['grid'], $cats_status);
			}
		}
		else
		{
			list($button) = @each($content['button']);
			$result = $categories = array ();
			$users = array_keys($GLOBALS['egw']->accounts->search(array('type'=>'accounts', active=>true)));

			// report button pressed
			if (!empty($button))
			{
				// shift the grid content by one because of the reserved first row
				// for the header.
				array_shift($content['grid']);
				Api\Framework::ajax_set_preference('calendar', 'category_report',$content['grid']);

				foreach ($content['grid'] as $key => $row)
				{
					if ($row['enable'])
					{
						$categories [$key] = $row['cat_id'];
					}
				}

				// query calendar for events
				$events = $this->bo->search(array(
					'start' => $content['start'],
					'end' => $content['end'],
					'users' => $users,
					'cat_id' => $categories,
					'daywise' => true
				));
				$days_sum = $weeks_sum = array ();
				// iterate over found events
				foreach($events as $day_index => $day_events)
				{
					if (is_array($day_events))
					{
						foreach ($categories as $row_id => $cat_id)
						{
							$this->process_days(
									$weeks_sum,
									$days_sum[$day_index],
									$day_events,
									$cat_id,
									$content['grid'][$row_id]['holidays'],
									$content['grid'][$row_id]['weekend'],
									$content['grid'][$row_id]['min_days'],
									$content['grid'][$row_id]['unit']
							);
						}
					}
				}

				asort($days_sum);
				ksort($days_sum);
				$days_output = $min_days_output = array ();

				foreach($days_sum as $day_index => $users)
				{
					foreach ($users as $user_id => $cat_ids)
					{
						foreach ($cat_ids as $cat_id => $event)
						{
							foreach ($event as $e)
							{
								$days_output[$user_id][$cat_id][$e['event_id']]['days'] =
										$days_output[$user_id][$cat_id][$e['event_id']]['days'] + (int)$e['amount'];
								$days_output[$user_id][$cat_id][$e['event_id']]['unit'] = $e['unit'];
							}
						}
					}
				}

				foreach ($weeks_sum as $user_id => $weeks)
				{
					foreach ($weeks as $week_id => $cat_ids)
					{
						foreach ($cat_ids as $cat_id => $events)
						{
							foreach ($events as $event_id => $e)
							{
								if ($event_id !== 'min_days')
								{
									$days = $weeks_sum[$user_id][$week_id][$cat_id][$event_id];
									$min_days_output[$user_id][$cat_id][$event_id]['days'] =
											$min_days_output[$user_id][$cat_id][$event_id]['days'] +
											(count($days) >= (int)$events['min_days']?self::add_days($days):0);
								}
							}
						}
					}
				}

				// Replace value of those cats who have min_days set on with regular day calculation
				// result array structure:
				// [user_ids] =>
				//      [cat_ids] =>
				//          [event_ids] => [
				//             days: amount of event in second,
				//              unit: unit to be shown as output (in second, eg. 3600)
				//          ]
				//
				$result = array_replace_recursive($days_output, $min_days_output);
				$csv_header = $csv_raw_rows = array ();

				// create csv header
				foreach ($categories as $cat_id)
				{
					$csv_header [] = $api_cats->id2name($cat_id);
				}
				array_unshift($csv_header, 'user_fullname');

				// file pointer
				$fp = fopen('php://output', 'w');

				// sort out events and categories
				foreach ($result as $user_id => $userData)
				{
					foreach ($userData as $cat_id => $events)
					{
						foreach ($events as $event_id => $values)
						{
							$eid = $event_id;
							$r = array();
							foreach ($userData as $c_id => &$e)
							{
								if (!$e) continue;
								$top = array_shift($e);
								$r[$eid][$c_id] = ceil($top['days']? $top['days'] / $top['unit']: 0);
							}
							if ($r) $csv_raw_rows[$user_id][] = $r;
						}
					}
				}


				// printout csv header into file
				fputcsv($fp, array_values($csv_header));

				// set header to download csv file
				header('Content-type: text/csv');
				header('Content-Disposition: attachment; filename="report.csv"');

				// iterate over csv rows for each user to print them out into csv file
				foreach ($result as $user_id => $userData)
				{
					foreach ($csv_raw_rows[$user_id] as &$raw_row)
					{
						$cats_row = array();
						$raw_row = array_shift($raw_row);
						foreach ($categories as $cat_id)
						{
							$cats_row [$cat_id] = $raw_row[$cat_id]?$raw_row[$cat_id]:0;
						}
						// printout each row into file
						fputcsv($fp, array_values(array(Api\Accounts::id2name($user_id, 'account_fullname')) + $cats_row));
					}
				}
				// echo out csv file
				fpassthru($fp);
				exit();
			}
		}

		// unit selectbox options
		$sel_options['unit'] = array (
			86400 => array('label' => lang('day'), 'title'=>'Output value in day'),
			3600 => array('label' => lang('hour'), 'title'=>'Output value in hour'),
			60 => array('label' => lang('minute'), 'title'=>'Output value in minute'),
			1  => array('label' => lang('second'), 'title'=>'Output value in second')
		);
		// Add an extra row for the grid header
		array_unshift($content['grid'],array(''=> ''));
		$preserv = $content;
		$this->tmpl->exec('calendar.calendar_category_report.index', $content, $sel_options, array(), $preserv, 2);
	}
}
