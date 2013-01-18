<?php
class Single_logon_ext {
	var $name = 'Single Logon';
	var $version = '0.2';
	var $descriptions = 'Prevents multiple users from using one account simultaneously';
	var $settings_exist = 'n';
	var $docs_url = 'http://www.epicvoyage.org/ee/';
	var $settings = array();

	function __construct($settings = '') {
		$this->EE =& get_instance();

		return;
	}

	/* Kill other logins for this username when we log in to the front end. */
	function on_login() {
		$user = $this->EE->input->post('username');

		if (!empty($user)) {
			# Ensure that we do not get caught by an old IP notice.
			$this->EE->db->where('ip_address', $this->EE->input->ip_address());
			$this->EE->db->delete('single_logon');

			# Check whether this user is already logged in...
			$this->EE->db->select('sessions.*');
			$this->EE->db->from('members');
			$this->EE->db->join('sessions', 'sessions.member_id = members.member_id');
			$this->EE->db->where('members.username', $user);
			$query = $this->EE->db->get();

			# If we found a login...
			if ($query->num_rows() > 0) {
				# Copy it to our temp table...
				foreach ($query->result() as $row) {
					$this->EE->db->insert('single_logon', array(
						'ip_address' => $row->ip_address,
						'user_agent' => $row->user_agent
					));
				}

				# And then delete the records.
				$this->EE->db->where('member_id', $query->row('member_id'));
				$this->EE->db->delete('sessions');
			}
		}

		return;
	}

	/* Kill other logins for this member_id on every page load. */
	function on_page_load(&$sess) {
		# If the current user is logged out...
		if (!isset($sess->userdata['member_id']) || empty($sess->userdata['member_id'])) {
			# Look up their IP address and user agent.
			$this->EE->db->where('ip_address', $sess->userdata['ip_address']);
			$this->EE->db->where('user_agent', $sess->userdata['user_agent']);
			$this->EE->db->where('stamp > NOW() - INTERVAL 1 DAY');
			$query = $this->EE->db->get('single_logon');

			# If found, show them an error message one time.
			if ($query->num_rows() > 0) {
				$this->EE->db->where('ip_address', $sess->userdata['ip_address']);
				$this->EE->db->where('user_agent', $sess->userdata['user_agent']);
				$this->EE->db->delete('single_logon');

				$this->EE->lang->loadfile('core');
				$this->EE->lang->loadfile('design');
				$this->EE->lang->loadfile('single_logon');

				$this->EE->output->show_message(array(
					'title' => $this->EE->lang->line('error'),
					'heading' => $this->EE->lang->line('general_error'),
					'content' => '<ul><li>'.lang('someone_else_logged_in_goodbye').'</li></ul>',
					'redirect' => '',
					'link' => array($this->EE->functions->fetch_site_index(TRUE), $this->EE->lang->line('site_homepage'))
				), 0);

				die;
			}
		//} elseif (!empty($sess->userdata['member_id'])) {
		//	$this->EE->db->where('session_id !=', $sess->userdata['session_id']);
		//	$this->EE->db->where('member_id', $sess->userdata['member_id']);
		//	$this->EE->db->delete('sessions');
		}

		return true;
	}

	function settings() {
		return array();
	}

	function activate_extension() {
		# Create our "deleted" notification table
		$this->EE->load->dbforge();
		$this->EE->dbforge->add_field(array(
			'ip_address' => array(
				'type' => 'varchar',
				'constraint' => 255
			), 'user_agent' => array(
				'type' => 'varchar',
				'constraint' => 255
			), 'stamp' => array(
				'type' => 'timestamp'
			)
		));

		$this->EE->dbforge->add_key('ip_address');
		$this->EE->dbforge->add_key('user_agent');
		$this->EE->dbforge->create_table('single_logon');

		# Prepare to dump data into the database.
		$hooks = array(
			'member_member_login_start' => 'on_login',
			'sessions_end' => 'on_page_load'
		);
		$data = array(
			'class' => __CLASS__,
			'settings' => '',
			'priority' => 1,
			'version' => $this->version,
			'enabled' => 'y'
		);

		# Sign up for our hooks!
		foreach ($hooks as $hook => $func) {
			$data['hook'] = $hook;
			$data['method'] = $func;
			$this->EE->db->insert('extensions', $data);
		}

		return;
	}

	function update_extension($current = '') {
		# We rewrote a lot for 0.1 -> 0.2 and there are no settings to migrate.
		if ($current == '0.1') {
			$this->disable_extension();
			$this->activate_extension();
		}

		return;
	}

	function disable_extension() {
		# Disable our hooks.
		$this->EE->db->where('class', __CLASS__);
		$this->EE->db->delete('extensions');

		# And delete our table...
		$this->EE->load->dbforge();
		$this->EE->dbforge->drop_table('single_logon');
	}
}
