<?php

// Tripwire API URL
define( 'TW_API_URL', 'https://yourtripwire.com/api.php' );

// Tripwire username
define( 'TW_API_USER', 'username' );

// Tripwire password
define( 'TW_API_PASS', 'password' );

// Slack webhook URL
define( 'SLACK_URL', 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX' );

// Path for reading/writing chain cache
define( 'CACHE_DIR', __DIR__ . "/cache/" );

// EVE Online ESI URL
define( 'ESI_URL', 'https://esi.evetech.net/latest' );

// Chain(s) 
$chains = array(
	'Thera' => array( // Display name
		'systemID' => '31000005', // System ID
		'mask' => 'XXX.X', // Mask ID
		'destinations' => array(
			'30000142' => 'Jita', // 'System ID' => 'Display Name'
		),
		'slack' => array(
			'channel' => '#tripwire-pings', // Channel to send pings
			'username' => 'Tripwire', // Bot name
			'icon_emoji' => ':tripwire:', // Bot emoji
		),
	),
);