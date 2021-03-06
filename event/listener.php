<?php
/**
*
* Activity 24 hours extension for the phpBB Forum Software package.
*
* @copyright (c) 2015 Rich McGirr (RMcGirr83)
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace rmcgirr83\activity24hours\event;

/**
* @ignore
*/
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
* Event listener
*/
class listener implements EventSubscriberInterface
{
	/** @var \phpbb\cache\service */
	protected $cache;

	/** @var \phpbb\db\driver\driver */
	protected $db;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\user */
	protected $user;

	public function __construct(
		\phpbb\cache\service $cache,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\template\template $template,
		\phpbb\user $user)
	{
		$this->cache = $cache;
		$this->db = $db;
		$this->template = $template;
		$this->user = $user;
	}

	/**
	* Assign functions defined in this class to event listeners in the core
	*
	* @return array
	* @static
	* @access public
	*/
	static public function getSubscribedEvents()
	{
		return array(
			'core.index_modify_page_title'			=> 'display_24_hour_stats',
		);
	}

	/**
	* Display stats on index page
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function display_24_hour_stats($event)
	{
		// if the user is a bot, we won’t even process this function...
		if ($this->user->data['is_bot'])
		{
			return;
		}

		$this->user->add_lang_ext('rmcgirr83/activity24hours', 'common');
		// obtain user activity data
		$active_users = $this->obtain_active_user_data();

		// obtain posts/topics/new users activity
		$activity = $this->obtain_activity_data();

		// Obtain guests data
		$total_guests_online_24 = $this->obtain_guest_count_24();

		// 24 hour users online list, assign to the template block: lastvisit
		foreach ((array) $active_users as $row)
		{
				$max_last_visit = max($row['user_lastvisit'], $row['session_time']);
				$hover_info = ' title="' . $this->user->format_date($max_last_visit) . '"';

				$this->template->assign_block_vars('lastvisit', array(
					'USERNAME_FULL'	=> '<span' . $hover_info . '>' . get_username_string((($row['user_type'] == USER_IGNORE) ? 'no_profile' : 'full'), $row['user_id'], $row['username'], $row['user_colour']) . '</span>',
				));
		}

		// assign the stats to the template.
		$this->template->assign_vars(array(
			'USERS_24HOUR_TOTAL'	=> $this->user->lang('USERS_24HOUR_TOTAL', sizeof($active_users)),
			'USERS_ACTIVE'			=> sizeof($active_users),
			'HOUR_TOPICS'			=> $this->user->lang('24HOUR_TOPICS', $activity['topics']),
			'HOUR_POSTS'			=> $this->user->lang('24HOUR_POSTS', $activity['posts']),
			'HOUR_USERS'			=> $this->user->lang('24HOUR_USERS', $activity['users']),
			'GUEST_ONLINE_24'		=> $this->user->lang('GUEST_ONLINE_24', $total_guests_online_24),
		));
	}

	/**
	 * Obtain an array of active users over the last 24 hours.
	 *
	 * @return array
	 */
	private function obtain_active_user_data()
	{
		if (($active_users = $this->cache->get('_24hour_users')) === false)
		{
			$active_users = array();

			// grab a list of users who are currently online
			// and users who have visited in the last 24 hours
			$sql_ary = array(
				'SELECT'	=> 'u.user_id, u.user_colour, u.username, u.user_type, u.user_lastvisit, MAX(s.session_time) as session_time',
				'FROM'		=> array(USERS_TABLE => 'u'),
				'LEFT_JOIN'	=> array(
					array(
						'FROM'	=> array(SESSIONS_TABLE => 's'),
						'ON'	=> 's.session_user_id = u.user_id',
					),
				),
				'WHERE'		=> 'u.user_lastvisit > ' . (time() - 86400) . ' OR s.session_user_id <> ' . ANONYMOUS,
				'GROUP_BY'	=> 'u.user_id',
				'ORDER_BY'	=> 'u.username',
			);

			$result = $this->db->sql_query($this->db->sql_build_query('SELECT', $sql_ary));

			while ($row = $this->db->sql_fetchrow($result))
			{
				$active_users[$row['user_id']] = $row;
			}
			$this->db->sql_freeresult($result);

			// cache this data for 1 hour, this improves performance
			$this->cache->put('_24hour_users', $active_users, 3600);
		}

		return $active_users;
	}

	/**
	 * obtained cached 24 hour activity data
	 *
	 * @return array
	 */
	private function obtain_activity_data()
	{
		if (($activity = $this->cache->get('_24hour_activity')) === false)
		{
			// set interval to 24 hours ago
			$interval = time() - 86400;

			$activity = array();

			// total new posts in the last 24 hours
			$sql = 'SELECT COUNT(post_id) AS new_posts
					FROM ' . POSTS_TABLE . '
					WHERE post_time > ' . $interval;
			$result = $this->db->sql_query($sql);
			$activity['posts'] = $this->db->sql_fetchfield('new_posts');
			$this->db->sql_freeresult($result);

			// total new topics in the last 24 hours
			$sql = 'SELECT COUNT(topic_id) AS new_topics
					FROM ' . TOPICS_TABLE . '
					WHERE topic_time > ' . $interval;
			$result = $this->db->sql_query($sql);
			$activity['topics'] = $this->db->sql_fetchfield('new_topics');
			$this->db->sql_freeresult($result);

			// total new users in the last 24 hours, counts inactive users as well
			$sql = 'SELECT COUNT(user_id) AS new_users
					FROM ' . USERS_TABLE . '
					WHERE user_regdate > ' . $interval;
			$result = $this->db->sql_query($sql);
			$activity['users'] = $this->db->sql_fetchfield('new_users');
			$this->db->sql_freeresult($result);

			// cache this data for 1 hour, this improves performance
			$this->cache->put('_24hour_activity', $activity, 3600);
		}

		return $activity;
	}

	private function obtain_guest_count_24()
	{
		$total_guests_online_24 = 0;
		// Get number of online guests for the past 24 hours
		// caching and main sql if none yet
		if (($total_guests_online_24 = $this->cache->get('_total_guests_online_24')) === false)
		{
			// teh time
			$interval = time() - 86400;

			if ($this->db->get_sql_layer() === 'sqlite' || $this->db->get_sql_layer() === 'sqlite3')
			{
				$sql = 'SELECT COUNT(session_ip) as num_guests_24
					FROM (
						SELECT DISTINCT session_ip
						FROM ' . SESSIONS_TABLE . '
						WHERE session_user_id = ' . ANONYMOUS . '
							AND session_time >= ' . ($interval - ((int) ($interval % 60))) . ')';
			}
			else
			{
				$sql = 'SELECT COUNT(DISTINCT session_ip) as num_guests_24
					FROM ' . SESSIONS_TABLE . '
					WHERE session_user_id = ' . ANONYMOUS . '
						AND session_time >= ' . ($interval - ((int) ($interval % 60)));
			}
			$result = $this->db->sql_query($sql);
			$total_guests_online_24 = (int) $this->db->sql_fetchfield('num_guests_24');

			$this->db->sql_freeresult($result);

			// cache this stuff for, ohhhh, how about 5 minutes
			// change 300 to whatever number to reduce or increase the cache time
			$this->cache->put('_total_guests_online_24', $total_guests_online_24, 300);
		}

		return $total_guests_online_24;
	}
}
