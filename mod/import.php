<?php

// Import a channel, either by direct file upload or via
// connection to original server. 

require_once('include/Contact.php');
require_once('include/zot.php');
require_once('include/identity.php');

function import_post(&$a) {

	$account_id = get_account_id();
	if(! $account_id)
		return;

	$max_identities = account_service_class_fetch($account_id,'total_identities');
	$max_friends = account_service_class_fetch($account_id,'total_channels');
	$max_feeds = account_service_class_fetch($account_id,'total_feeds');

	if($max_identities !== false) {
		$r = q("select channel_id from channel where channel_account_id = %d",
			intval($account_id)
		);
		if($r && count($r) > $max_identities) {
			notice( sprintf( t('Your service plan only allows %d channels.'), $max_identities) . EOL);
			return;
		}
	}


	$data     = null;
	$seize    = ((x($_REQUEST,'make_primary')) ? intval($_REQUEST['make_primary']) : 0);
	$import_posts = ((x($_REQUEST,'import_posts')) ? intval($_REQUEST['import_posts']) : 0);
	$src      = $_FILES['filename']['tmp_name'];
	$filename = basename($_FILES['filename']['name']);
	$filesize = intval($_FILES['filename']['size']);
	$filetype = $_FILES['filename']['type'];

	$completed = ((array_key_exists('import_step',$_SESSION)) ? intval($_SESSION['import_step']) : 0);
	if($completed)
		logger('saved import step: ' . $_SESSION['import_step']);

	if($src) {

		// This is OS specific and could also fail if your tmpdir isn't very large
		// mostly used for Diaspora which exports gzipped files.

		if(strpos($filename,'.gz')){
			@rename($src,$src . '.gz');
			@system('gunzip ' . escapeshellarg($src . '.gz'));
		}

		if($filesize) {
			$data = @file_get_contents($src);
		}
		unlink($src);
	}

	if(! $src) {
		$old_address = ((x($_REQUEST,'old_address')) ? $_REQUEST['old_address'] : '');
		if(! $old_address) {
			logger('mod_import: nothing to import.');
			notice( t('Nothing to import.') . EOL);
			return;
		}

		$email    = ((x($_REQUEST,'email'))    ? $_REQUEST['email']    : '');
		$password = ((x($_REQUEST,'password')) ? $_REQUEST['password'] : '');

		$channelname = substr($old_address,0,strpos($old_address,'@'));
		$servername  = substr($old_address,strpos($old_address,'@')+1);

		$scheme = 'https://';
		$api_path = '/api/red/channel/export/basic?f=&channel=' . $channelname;
		if($import_posts)
			$api_path .= '&posts=1';
		$binary = false;
		$redirects = 0;
		$opts = array('http_auth' => $email . ':' . $password);
		$url = $scheme . $servername . $api_path;
		$ret = z_fetch_url($url, $binary, $redirects, $opts);
		if(! $ret['success'])
			$ret = z_fetch_url('http://' . $servername . $api_path, $binary, $redirects, $opts);
		if($ret['success'])
			$data = $ret['body'];
		else
			notice( t('Unable to download data from old server') . EOL);

	}

	if(! $data) {
		logger('mod_import: empty file.');
		notice( t('Imported file is empty.') . EOL);
		return;
	}

	$data = json_decode($data,true);

//	logger('import: data: ' . print_r($data,true));
//	print_r($data);


	if(array_key_exists('user',$data) && array_key_exists('version',$data)) {
		require_once('include/Import/import_diaspora.php');
		import_diaspora($data);
		return;
	}

	if(array_key_exists('compatibility',$data) && array_key_exists('database',$data['compatibility'])) {
		$v1 = substr($data['compatibility']['database'],-4);
		$v2 = substr(DB_UPDATE_VERSION,-4);
		if($v2 > $v1) {
			$t = sprintf( t('Warning: Database versions differ by %1$d updates.'), $v2 - $v1 ); 
			notice($t);
		}
	}

	// import channel

	if(array_key_exists('channel',$data)) {
		$channel = $data['channel'];

		if($completed < 1) {

			if(! array_key_exists('channel_system',$channel)) {
				$channel['channel_system']  = (($channel['channel_pageflags'] & 0x1000) ? 1 : 0);
				$channel['channel_removed'] = (($channel['channel_pageflags'] & 0x8000) ? 1 : 0);
			}

			$r = q("select * from channel where (channel_guid = '%s' or channel_hash = '%s' or channel_address = '%s' ) limit 1",
				dbesc($channel['channel_guid']),
				dbesc($channel['channel_hash']),
				dbesc($channel['channel_address'])
			);

			// We should probably also verify the hash 
	
			if($r) {
				if($r[0]['channel_guid'] === $channel['channel_guid'] || $r[0]['channel_hash'] === $channel['channel_hash']) {
					logger('mod_import: duplicate channel. ', print_r($channel,true));
					notice( t('Cannot create a duplicate channel identifier on this system. Import failed.') . EOL);
					return;
				}
				else {
					// try at most ten times to generate a unique address.
					$x = 0;
					$found_unique = false;
					do {
						$tmp = $channel['channel_address'] . mt_rand(1000,9999);
						$r = q("select * from channel where channel_address = '%s' limit 1",
							dbesc($tmp)
						);
						if(! $r) {
							$channel['channel_address'] = $tmp;
							$found_unique = true;
							break;
						}
						$x ++;
					} while ($x < 10);
					if(! $found_unique) {
						logger('mod_import: duplicate channel. randomisation failed.', print_r($channel,true));
						notice( t('Unable to create a unique channel address. Import failed.') . EOL);
						return;
					}
				}		
			}

			unset($channel['channel_id']);
			$channel['channel_account_id'] = get_account_id();
			$channel['channel_primary'] = (($seize) ? 1 : 0);
	
			dbesc_array($channel);

			$r = dbq("INSERT INTO channel (`" 
				. implode("`, `", array_keys($channel)) 
				. "`) VALUES ('" 
				. implode("', '", array_values($channel)) 
				. "')" );

			if(! $r) {
				logger('mod_import: channel clone failed. ', print_r($channel,true));
				notice( t('Channel clone failed. Import failed.') . EOL);
				return;
			}

			$r = q("select * from channel where channel_account_id = %d and channel_guid = '%s' limit 1",
				intval(get_account_id()),
				$channel['channel_guid']   // Already dbesc'd
			);
			if(! $r) {
				logger('mod_import: channel not found. ', print_r($channel,true));
				notice( t('Cloned channel not found. Import failed.') . EOL);
				return;
			}
			// reset
			$channel = $r[0];

			set_default_login_identity(get_account_id(),$channel['channel_id'],false);
			logger('import step 1');
			$_SESSION['import_step'] = 1;
			ref_session_write(session_id(), serialize($_SESSION));
		}
	}
	else {
		$r = q("select * from channel where channel_account_id = %d and channel_guid = '%s' limit 1",
			intval(get_account_id()),
			dbesc($channel['channel_guid'])
		);
		if($r)
			$channel = $r[0];
		else {
			logger('mod_import: channel not found. ', print_r($channel,true));
			notice( t('Cloned channel not found. Import failed.') . EOL);
			return;
		}
	}

	if($completed < 2) {

		$configs = $data['config'];
		if($configs) {
			foreach($configs as $config) {
				unset($config['id']);
				$config['uid'] = $channel['channel_id'];
				dbesc_array($config);
				$r = dbq("INSERT INTO pconfig (`" 
					. implode("`, `", array_keys($config)) 
					. "`) VALUES ('" 
					. implode("', '", array_values($config)) 
					. "')" );
			}
		}
		logger('import step 2');
		$_SESSION['import_step'] = 2;
		ref_session_write(session_id(), serialize($_SESSION));
	}



	if($completed < 3) {

		if($data['photo']) {
			require_once('include/photo/photo_driver.php');
			import_channel_photo(base64url_decode($data['photo']['data']),$data['photo']['type'],get_account_id(),$channel['channel_id']);
		}

		$profiles = $data['profile'];
		if($profiles) {
			foreach($profiles as $profile) {
				unset($profile['id']);
				$profile['aid'] = get_account_id();
				$profile['uid'] = $channel['channel_id'];

				// we are going to reset all profile photos to the original
				// somebody will have to fix this later and put all the applicable photos into the export
	
				$profile['photo'] = z_root() . '/photo/profile/l/' . $channel['channel_id'];
				$profile['thumb'] = z_root() . '/photo/profile/m/' . $channel['channel_id'];


				dbesc_array($profile);
				$r = dbq("INSERT INTO profile (`" 
					. implode("`, `", array_keys($profile)) 
					. "`) VALUES ('" 
					. implode("', '", array_values($profile)) 
					. "')" );
			}
		}
		logger('import step 3');
		$_SESSION['import_step'] = 3;
		ref_session_write(session_id(), serialize($_SESSION));
	}


	if($completed < 4) {
		$hublocs = $data['hubloc'];
		if($hublocs) {
			foreach($hublocs as $hubloc) {

				if(! array_key_exists('hubloc_primary',$hublocs)) {
					$hubloc['hubloc_primary'] = (($hubloc['hubloc_flags'] & 0x0001) ? 1 : 0);
					$hubloc['hubloc_orphancheck'] = (($hubloc['hubloc_flags'] & 0x0004) ? 1 : 0);
					$hubloc['hubloc_error'] = (($hubloc['hubloc_status'] & 0x0003) ? 1 : 0);
					$hubloc['hubloc_deleted'] = (($hubloc['hubloc_flags'] & 0x1000) ? 1 : 0);
				}

				$arr = array(
					'guid' => $hubloc['hubloc_guid'],
					'guid_sig' => $hubloc['guid_sig'],
					'url' => $hubloc['hubloc_url'],
					'url_sig' => $hubloc['hubloc_url_sig']
				);
				if(($hubloc['hubloc_hash'] === $channel['channel_hash']) && intval($hubloc['hubloc_primary']) && ($seize))
					$hubloc['hubloc_primary'] = 0;

				if(! zot_gethub($arr)) {				
					unset($hubloc['hubloc_id']);
					dbesc_array($hubloc);
		
					$r = dbq("INSERT INTO hubloc (`" 
						. implode("`, `", array_keys($hubloc)) 
						. "`) VALUES ('" 
						. implode("', '", array_values($hubloc)) 
						. "')" );
				}
			}
		}
		logger('import step 4');
		$_SESSION['import_step'] = 4;
		ref_session_write(session_id(), serialize($_SESSION));
	}

	if($completed < 5) {
		// create new hubloc for the new channel at this site

		$r = q("insert into hubloc ( hubloc_guid, hubloc_guid_sig, hubloc_hash, hubloc_addr, hubloc_network, hubloc_primary, 
			hubloc_url, hubloc_url_sig, hubloc_host, hubloc_callback, hubloc_sitekey )
			values ( '%s', '%s', '%s', '%s', '%s', %d, '%s', '%s', '%s', '%s', '%s' )",
			dbesc($channel['channel_guid']),
			dbesc($channel['channel_guid_sig']),
			dbesc($channel['channel_hash']),
			dbesc($channel['channel_address'] . '@' . get_app()->get_hostname()),
			dbesc('zot'),
			intval(($seize) ? 1 : 0),
			dbesc(z_root()),
			dbesc(base64url_encode(rsa_sign(z_root(),$channel['channel_prvkey']))),
			dbesc(get_app()->get_hostname()),
			dbesc(z_root() . '/post'),
			dbesc(get_config('system','pubkey'))
		);
	
		// reset the original primary hubloc if it is being seized

		if($seize) {
			$r = q("update hubloc set hubloc_primary = 0 where hubloc_primary = 1 and hubloc_hash = '%s' and hubloc_url != '%s' ",
				dbesc($channel['channel_hash']),
				dbesc(z_root())
			);
		}
		logger('import step 5');
		$_SESSION['import_step'] = 5;
		ref_session_write(session_id(), serialize($_SESSION));
	}
 

	if($completed < 6) {

		// import xchans and contact photos

		if($seize) {

			// replace any existing xchan we may have on this site if we're seizing control

			$r = q("delete from xchan where xchan_hash = '%s'",
				dbesc($channel['channel_hash'])
			);

			$r = q("insert into xchan ( xchan_hash, xchan_guid, xchan_guid_sig, xchan_pubkey, xchan_photo_l, xchan_photo_m, xchan_photo_s, xchan_addr, xchan_url, xchan_follow, xchan_connurl, xchan_name, xchan_network, xchan_photo_date, xchan_name_date, xchan_hidden, xchan_orphan, xchan_censored, xchan_selfcensored, xchan_system, xchan_pubforum, xchan_deleted ) values ('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', %d, %d, %d, %d, %d, %d, %d )",
				dbesc($channel['channel_hash']),
				dbesc($channel['channel_guid']),
				dbesc($channel['channel_guid_sig']),
				dbesc($channel['channel_pubkey']),
				dbesc($a->get_baseurl() . "/photo/profile/l/" . $channel['channel_id']),
				dbesc($a->get_baseurl() . "/photo/profile/m/" . $channel['channel_id']),
				dbesc($a->get_baseurl() . "/photo/profile/s/" . $channel['channel_id']),
				dbesc($channel['channel_address'] . '@' . get_app()->get_hostname()),
				dbesc(z_root() . '/channel/' . $channel['channel_address']),
				dbesc(z_root() . '/follow?f=&url=%s'),
				dbesc(z_root() . '/poco/' . $channel['channel_address']),
				dbesc($channel['channel_name']),
				dbesc('zot'),
				dbesc(datetime_convert()),
				dbesc(datetime_convert()),
				0,0,0,0,0,0,0
			);
		}
		logger('import step 6');
		$_SESSION['import_step'] = 6;
		ref_session_write(session_id(), serialize($_SESSION));
	}

	if($completed < 7) {

		$xchans = $data['xchan'];
		if($xchans) {
			foreach($xchans as $xchan) {
				if(! array_key_exists('xchan_hidden',$xchan)) {
					$xchan['xchan_hidden']       = (($xchan['xchan_flags'] & 0x0001) ? 1 : 0);
					$xchan['xchan_orphan']       = (($xchan['xchan_flags'] & 0x0002) ? 1 : 0);
					$xchan['xchan_censored']     = (($xchan['xchan_flags'] & 0x0004) ? 1 : 0);
					$xchan['xchan_selfcensored'] = (($xchan['xchan_flags'] & 0x0008) ? 1 : 0);
					$xchan['xchan_system']       = (($xchan['xchan_flags'] & 0x0010) ? 1 : 0);
					$xchan['xchan_pubforum']     = (($xchan['xchan_flags'] & 0x0020) ? 1 : 0);
					$xchan['xchan_deleted']      = (($xchan['xchan_flags'] & 0x1000) ? 1 : 0);
				}

				$r = q("select xchan_hash from xchan where xchan_hash = '%s' limit 1",
					dbesc($xchan['xchan_hash'])
				);
				if($r)
					continue;

				dbesc_array($xchan);
		
				$r = dbq("INSERT INTO xchan (`" 
					. implode("`, `", array_keys($xchan)) 
					. "`) VALUES ('" 
					. implode("', '", array_values($xchan)) 
					. "')" );

	
				require_once('include/photo/photo_driver.php');
				$photos = import_profile_photo($xchan['xchan_photo_l'],$xchan['xchan_hash']);
				if($photos[4])
					$photodate = NULL_DATE;
				else
					$photodate = $xchan['xchan_photo_date'];

				$r = q("update xchan set xchan_photo_l = '%s', xchan_photo_m = '%s', xchan_photo_s = '%s', xchan_photo_mimetype = '%s', xchan_photo_date = '%s'
					where xchan_hash = '%s'",
					dbesc($photos[0]),
					dbesc($photos[1]),
					dbesc($photos[2]),
					dbesc($photos[3]),
					dbesc($photodate),
					dbesc($xchan['xchan_hash'])
				);
			
			}
		}
		logger('import step 7');
		$_SESSION['import_step'] = 7;
		ref_session_write(session_id(), serialize($_SESSION));
	}



// FIXME - ensure we have an xchan if somebody is trying to pull a fast one


	if($completed < 8) {	
		$friends = 0;
		$feeds = 0;

		// import contacts
		$abooks = $data['abook'];
		if($abooks) {
			foreach($abooks as $abook) {
				if($max_friends !== false && $friends > $max_friends)
					continue;
				if($max_feeds !== false && intval($abook['abook_feed']) && ($feeds > $max_feeds))
					continue;

				unset($abook['abook_id']);
				unset($abook['abook_rating']);
				unset($abook['abook_rating_text']);
				$abook['abook_account'] = get_account_id();
				$abook['abook_channel'] = $channel['channel_id'];
				if(! array_key_exists('abook_blocked',$abook)) {
					$abook['abook_blocked']     = (($abook['abook_flags'] & 0x0001 ) ? 1 : 0);
					$abook['abook_ignored']     = (($abook['abook_flags'] & 0x0002 ) ? 1 : 0);
					$abook['abook_hidden']      = (($abook['abook_flags'] & 0x0004 ) ? 1 : 0);
					$abook['abook_archived']    = (($abook['abook_flags'] & 0x0008 ) ? 1 : 0);
					$abook['abook_pending']     = (($abook['abook_flags'] & 0x0010 ) ? 1 : 0);
					$abook['abook_unconnected'] = (($abook['abook_flags'] & 0x0020 ) ? 1 : 0);
					$abook['abook_self']        = (($abook['abook_flags'] & 0x0080 ) ? 1 : 0);
					$abook['abook_feed']        = (($abook['abook_flags'] & 0x0100 ) ? 1 : 0);
				}

				if($abook['abook_self']) {
					$role = get_pconfig($channel['channel_id'],'system','permissions_role');
					if(($role === 'forum') || ($abook['abook_my_perms'] & PERMS_W_TAGWALL)) {
						q("update xchan set xchan_pubforum = 1 where xchan_hash = '%s' ",
							dbesc($abook['abook_xchan'])
						);
					}
				} 

				dbesc_array($abook);
				$r = dbq("INSERT INTO abook (`" 
					. implode("`, `", array_keys($abook)) 
					. "`) VALUES ('" 
					. implode("', '", array_values($abook)) 
					. "')" );

				$friends ++;
				if(intval($abook['abook_feed']))
					$feeds ++;
			}
		}
		logger('import step 8');
		$_SESSION['import_step'] = 8;
		ref_session_write(session_id(), serialize($_SESSION));
	}



	if($completed < 9) {
		$groups = $data['group'];
		if($groups) {
			$saved = array();
			foreach($groups as $group) {
				$saved[$group['hash']] = array('old' => $group['id']);
				unset($group['id']);
				$group['uid'] = $channel['channel_id'];
				dbesc_array($group);
				$r = dbq("INSERT INTO groups (`" 
					. implode("`, `", array_keys($group)) 
					. "`) VALUES ('" 
					. implode("', '", array_values($group)) 
					. "')" );
			}
			$r = q("select * from `groups` where uid = %d",
				intval($channel['channel_id'])
			);
			if($r) {
				foreach($r as $rr) {
					$saved[$rr['hash']]['new'] = $rr['id'];
				}
			} 
		}

		$group_members = $data['group_member'];
		if($group_members) {
			foreach($group_members as $group_member) {
				unset($group_member['id']);
				$group_member['uid'] = $channel['channel_id'];
				foreach($saved as $x) {
					if($x['old'] == $group_member['gid'])
						$group_member['gid'] = $x['new'];
				}
				dbesc_array($group_member);
				$r = dbq("INSERT INTO group_member (`" 
					. implode("`, `", array_keys($group_member)) 
					. "`) VALUES ('" 
					. implode("', '", array_values($group_member)) 
					. "')" );
			}
		}
		logger('import step 9');
		$_SESSION['import_step'] = 9;
		ref_session_write(session_id(), serialize($_SESSION));
	}

	$saved_notification_flags = notifications_off($channel['channel_id']);

	if($import_posts && array_key_exists('item',$data) && $data['item']) {

		foreach($data['item'] as $i) {
			$item = get_item_elements($i);

			$r = q("select id, edited from item where mid = '%s' and uid = %d limit 1",
				dbesc($item['mid']),
				intval($channel['channel_id'])
			);
			if($r) {
				if($item['edited'] > $r[0]['edited']) {
					$item['id'] = $r[0]['id'];
					$item['uid'] = $channel['channel_id'];
					item_store_update($item);
					continue;
				}	
			}
			else {
				$item['aid'] = $channel['channel_account_id'];
				$item['uid'] = $channel['channel_id'];
				$item_result = item_store($item);
			}

		}

	}

	notifications_on($channel['channel_id'],$saved_notification_flags);

	if(array_key_exists('item_id',$data) && $data['item_id']) {
		foreach($data['item_id'] as $i) {
			$r = q("select id from item where mid = '%s' and uid = %d limit 1",
				dbesc($i['mid']),
				intval($channel['channel_id'])
			);
			if(! $r)
				continue;
			$z = q("select * from item_id where service = '%s' and sid = '%s' and iid = %d and uid = %d limit 1",
				dbesc($i['service']),
				dbesc($i['sid']),
				intval($r[0]['id']),
				intval($channel['channel_id'])
			);
			if(! $z) {
				q("insert into item_id (iid,uid,sid,service) values(%d,%d,'%s','%s')",
					intval($r[0]['id']),
					intval($channel['channel_id']),
					dbesc($i['sid']),
					dbesc($i['service'])
				);
			}
		}
	}



// FIXME - ensure we have a self entry if somebody is trying to pull a fast one

	// send out refresh requests
	// notify old server that it may no longer be primary.

	proc_run('php','include/notifier.php','location',$channel['channel_id']);

	// This will indirectly perform a refresh_all *and* update the directory

	proc_run('php', 'include/directory.php', $channel['channel_id']);


	notice( t('Import completed.') . EOL);

	change_channel($channel['channel_id']);

	unset($_SESSION['import_step']);
	goaway(z_root() . '/network' );

}


function import_content(&$a) {

	if(! get_account_id()) {
		notice( t('You must be logged in to use this feature.'));
		return '';
	}

	$o = replace_macros(get_markup_template('channel_import.tpl'),array(
		'$title' => t('Import Channel'),
		'$desc' => t('Use this form to import an existing channel from a different server/hub. You may retrieve the channel identity from the old server/hub via the network or provide an export file.'),
		'$label_filename' => t('File to Upload'),
		'$choice' => t('Or provide the old server/hub details'),
		'$label_old_address' => t('Your old identity address (xyz@example.com)'),
		'$label_old_email' => t('Your old login email address'),
		'$label_old_pass' => t('Your old login password'),
		'$common' => t('For either option, please choose whether to make this hub your new primary address, or whether your old location should continue this role. You will be able to post from either location, but only one can be marked as the primary location for files, photos, and media.'),
		'$label_import_primary' => t('Make this hub my primary location'),
		'$label_import_posts' => t('Import existing posts if possible (experimental - limited by available memory'),
		'$pleasewait' => t('This process may take several minutes to complete. Please submit the form only once and leave this page open until finished.'), 
		'$email' => '',
		'$pass' => '',
		'$submit' => t('Submit')
	));

	return $o;

}
