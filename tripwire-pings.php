<?php

if ( php_sapi_name() != "cli" ) die(); // prevent web-access

require_once( 'config.php' );

foreach ( $chains as $chainID => $chain ) {
	echo "[DEBUG] Building chain: {$chainID}\n";
	$new = get_kspace( $chain );
	if ( is_cached( $chainID ) ) {
		echo "[DEBUG] Comparing to cache...\n";
		$old = get_cached( $chainID );
		$adds = array_diff_key( $new, $old );
		foreach ( $adds as $addID => $add ) {
			foreach ( $destinations as $destID => $dest ) {
				$jumps = $add[ $dest ]['shortest'];
				if ( $jumps <= 10 ) {
					echo "[DEBUG] Added: {$add['name']} ({$add['security']}) {$jumps}j {$dest}\n";
					ping_slack( 'added', $add['name'], $add['security'], $jumps, $dest, $chainID, $chain, $add );
				}
			}
		}
		$dels = array_diff_key( $old, $new );
		foreach ( $dels as $delID => $del ) {
			foreach ( $destinations as $destID => $dest ) {
				$jumps = $del[ $dest ]['shortest'];
				if ( $jumps <= 10 ) {
					echo "[DEBUG] Removed: {$del['name']} ({$del['security']}) {$jumps}j {$dest}\n";
					ping_slack( 'removed', $del['name'], $del['security'], $jumps, $dest, $chainID, $chain, $del );
				}
			}
		}
		if ( $adds || $dels ) {
			echo "[DEBUG] Updating cache...\n";
			put_cached( $chainID, $new );
		} else {
			echo "[DEBUG] No changes detected.\n";
		}
	} else {
		echo "[DEBUG] Saving to cache...\n";
		put_cached( $chainID, $new );
	}
}

function put_cached( $chain, $new ) {
	$file = CACHE_DIR . $chain . ".chain";
	file_put_contents( $file, serialize( $new ) );
}

function get_cached( $chain ) {
	$file = CACHE_DIR . $chain . ".chain";
	return unserialize( file_get_contents( $file ) );
}

function is_cached( $chain ) {
	$file = CACHE_DIR . $chain . ".chain";
	$cached = file_exists( $file );
	return $cached;
}

function get_kspace( $chain ) {
	$signatures = get_signatures( $chain['mask'] );
	$wormholes = get_wormholes( $chain['mask'] );
	if ( $signatures && $wormholes ) {
		$searching[] = $chain['systemID'];
		$inchain = [];
		while ( ! empty( $searching ) ) {
			$current = array_shift( $searching );
			foreach ( $signatures as $signature ) {
				if ( $signature->systemID == $current ) {
					//echo "in $current | $signature->id \n";
					$inchain[ $signature->id ] = $signature;
					foreach ( $wormholes as $wormhole ) {
						if ( $wormhole->initialID == $signature->id ) {
							$searching[] = $signatures[ $wormhole->secondaryID ]->systemID;
						}
					}
				}
			}
		}
		$systems = get_ksigs( $inchain );
		$ksigs = array();
		foreach ( $systems as $systemID => $system ) {
			$ksigs[ $systemID ] = array(
				'name' => $system['name'],
				'security' => $system['security'],
				'connection' => get_connection( $systemID, $signatures, $wormholes ),
			);
			foreach ( $chain['destinations'] as $destinationID => $destinationName ) {
				$ksigs[ $systemID ][ $destinationName ] = array(
					'shortest' => get_jumps( $systemID, $destinationID, 'shortest' ),
					'secure' => get_jumps( $systemID, $destinationID, 'secure' ),
				);
			}
		}
		return $ksigs;
	}
}

function get_signatures( $mask ) {
	echo "[DEBUG] " . TW_API_URL . "?maskID={$mask}&q=api/signatures \n";
	$ch = curl_init( TW_API_URL . "?maskID={$mask}&q=api/signatures" );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt( $ch, CURLOPT_USERPWD, TW_API_USER . ":" . TW_API_PASS );
	$result = curl_exec( $ch );
	$signatures = json_decode( $result );
	if ( $signatures ) {
		foreach ( $signatures as $signature ) $indexed[ $signature->id ] = $signature;
		return $indexed;
	} else {
		return $signatures;
	}
}

function get_wormholes( $mask ) {
	echo "[DEBUG] " . TW_API_URL . "?maskID={$mask}&q=api/wormholes \n";
	$ch = curl_init( TW_API_URL . "?maskID={$mask}&q=api/wormholes" );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt( $ch, CURLOPT_USERPWD, TW_API_USER . ":" . TW_API_PASS );
	$result = curl_exec( $ch );
	$wormholes = json_decode( $result );
	if ( $wormholes ) {
		foreach ( $wormholes as $wormhole ) $indexed[ $wormhole->id ] = $wormhole;
		return $indexed;
	} else {
		return $wormholes;
	}
}

function get_ksigs( $signatures ) {
	$systemIDs = [];
	$ksigs = [];
	foreach ( $signatures as $signature ) {
		if ( ( 'wormhole' == $signature->type ) && ( ! empty( $signature->systemID ) ) ) {
			$systemIDs[] = $signature->systemID;
		}
	}
	$systemIDs = array_unique( $systemIDs );
	foreach ( $systemIDs as $systemID ) {
		$system = get_system( $systemID );
		if ( ( isset( $system->security_status ) ) && ( $system->security_status > -0.99 ) ) {
			if ( $system->security_status >= 0.5 ) {
				$security = "HS";
			} else if ( $system->security_status > 0 ) {
				$security = "LS";
			} else {
				$security = "NS";
			}
			$ksigs[ $systemID ] = array( 'name' => $system->name, 'security' => $security );
		}
	}
	return $ksigs;
}

function get_system( $systemID ) {
	echo "[DEBUG] " . ESI_URL . "/universe/systems/{$systemID}/ \n";
	$ch = curl_init( ESI_URL . "/universe/systems/{$systemID}/" );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	$result = curl_exec( $ch );
	$system = json_decode( $result );
	return $system;
}

function get_jumps( $origin, $destination, $flag = 'shortest' ) {
	echo "[DEBUG] " . ESI_URL . "/route/{$origin}/{$destination}/?flag={$flag} \n";
	$ch = curl_init( ESI_URL . "/route/{$origin}/{$destination}/?flag={$flag}" );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	$result = curl_exec( $ch );
	$route = json_decode( $result );
	if ( is_array( $route ) ) {
		$jumps = count( $route ) - 1;
		return $jumps;
	}
}

function get_connection( $systemID, $signatures, $wormholes ) {
	foreach ( $signatures as $signature ) {
		if ( $signature->systemID == $systemID ) {
			foreach ( $wormholes as $wormhole ) {
				if ( ( $wormhole->initialID == $signature->id ) || ( $wormhole->secondaryID == $signature->id ) ) {
					$connection = array(
						'signature' => $signature,
						'wormhole' => $wormhole
					);
				}
			}
		}
	}
	return $connection;
}

function ping_slack( $change, $name, $security, $shortest, $destination, $chainID, $chain, $system ) {
	$slack = $chain['slack'];
	$character = $system['connection']['signature']->createdByName;
	$life = $system['connection']['wormhole']->life;
	$mass = $system['connection']['wormhole']->mass;
	$safest = $system[ $destination ]['secure'];
	$safenote = ( $safest > $shortest ) ? " shortest / {$safest}j safest" : "";
	$fields = array(
		array(
			"title" => "System",
			"value" => "$name ($security)",
			"short" => true,
		),
		array(
			"title" => "Connection",
			"value" => "$destination ({$shortest}j{$safenote})",
			"short" => true,
		),
	);
	if ( 'added' == $change ) {
		$color = "#50d25a";
		$fallback = "<!channel> New connection [{$chainID}] {$name} ({$shortest}j $destination)";
		$pretext = "<!channel> New connection [{$chainID}] (by {$character})";
		$fields[] = array(
			"title" => "Life",
			"value" => ucfirst( $life ),
			"short" => true,
		);
		$fields[] = array(
			"title" => "Mass",
			"value" => ucfirst( $mass ),
			"short" => true,
		);
	} else if ( 'removed' == $change ) {
		$color = "#ff4747";
		$fallback = "Dead connection [{$chainID}] {$name} ({$shortest}j $destination)";
		$pretext = "Dead connection [{$chainID}]";		
	}
	$slack['attachments'][] = array(
		"fallback" => $fallback,
		"pretext" => $pretext,
		"color" => $color,
		"fields" => $fields,
	);
	$ch = curl_init( SLACK_URL );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $slack ) );
	$result = curl_exec( $ch );
}