<?php
/*
	XSLOVZ is WHMCS Module to control OpenVZ nodes over php ssh2 extension
	Copyright (C) 2015  Mohammed H (hussein.m@xsl.tel)

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/
function XSLOVZ_ConfigOptions() {

	# Should return an array of the module options for each product - maximum of 24

    $configarray = array(
	 "VZ Config Name" => array( "Type" => "text", "Size" => "25", ),
	 "Disk Space" => array( "Type" => "text", "Size" => "5", "Description" => "gigabytes" ),
	 "Bandwidth" => array( "Type" => "text", "Size" => "5", "Description" => "gigabytes" ),
	 "RAM" => array( "Type" => "text", "Size" => "5", "Description" => "megabytes" ),
	 "vSwap RAM" => array( "Type" => "text", "Size" => "5", "Description" => "megabytes" ),
	 "CPU Cores" => array( "Type" => "text", "Size" => "5", "Description" => "" ),
	);
	
	return $configarray;

}

function XSLOVZ_CreateAccount($params) {
    # ** The variables listed below are passed into all module functions **
    $domain = escapeshellarg($params["domain"]);
	$username = escapeshellarg($params["username"]);
	$password = escapeshellarg($params["password"]);
	$ctid = intval($params["serviceid"]);
	$os = escapeshellarg($params["configoptions"]['Operating System']);
	
    # Product module option settings from ConfigOptions array above
    $configname = escapeshellarg($params["configoption1"]);
    $diskspace = escapeshellarg($params["configoption2"]);
    $bandwidth = $params["configoption3"];
    $ram = intval($params["configoption4"])*256;
	$swap = intval($params["configoption5"])*256;
	$cpus = intval($params["configoption6"]);
	
    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	
	// Get text from {} tags
	function getTextBetweenTags($string, $tagname) {
    $pattern = "/\{$tagname ?.*}(.*){\/$tagname}/s";
    preg_match($pattern, $string, $matches);
    return $matches[1];
	}
	
	// Get dedicated IP from server assigned IPs check it if its not used then assign it to a new VPS
	$find_server = select_query("tblhosting","",array("id"=>$params['serviceid']));
	$data = mysql_fetch_assoc($find_server);
	$serverid = $data["server"];
	$assigned_ips = select_query("tblservers","",array("id"=>$serverid));
	$data = mysql_fetch_assoc($assigned_ips);
	
	// Process IPv4
	$assignedips = getTextBetweenTags($data["assignedips"], "ip");
	$assignedips = preg_split('/\n|\r/', $assignedips, -1, PREG_SPLIT_NO_EMPTY);
	foreach ($assignedips as $ip) {
	$assignedip = ssh2_exec($connection,"/usr/sbin/vzlist -a -o ip -H| grep -o -w $ip");
	stream_set_blocking($assignedip, true);
	$assignedip = trim(stream_get_contents($assignedip));
	$ip = trim($ip);
	if ( $assignedip != $ip) {
		update_query("tblhosting",array("dedicatedip"=>"$ip"),array("id"=>$params['serviceid']));
		break;
		}
	}
	// Process IPv6
	$assignedipv6 = getTextBetweenTags($data["assignedips"], "ipv6");
	$assignedipv6 = preg_split('/\n|\r/', $assignedipv6, -1, PREG_SPLIT_NO_EMPTY);
	foreach ($assignedipv6 as $ipv6) {
	$assignedipv6 = ssh2_exec($connection,"/usr/sbin/vzlist -a -o ip -H| grep -o -w $ipv6");
	stream_set_blocking($assignedipv6, true);
	$assignedipv6 = trim(stream_get_contents($assignedipv6));
	$ipv6 = trim($ipv6);
	if ( $assignedipv6 != $ipv6) {
		update_query("tblhosting",array("assignedips"=>"$ipv6"),array("id"=>$params['serviceid']));
		break;
		}
	}
	
	$result = select_query("tblhosting","",array("id"=>$params['serviceid']));
	$data = mysql_fetch_assoc($result);
	$dedicated_ip = $data["dedicatedip"];
	$assignedipv6 = $data["assignedips"];
	
	// change username to root
	update_query("tblhosting",array("username"=>"root"),array("id"=>$params['serviceid']));
	
	$createvps = ssh2_exec($connection, "
	/usr/sbin/vzctl create $ctid --ostemplate $os --config $configname --hostname $domain --ipadd $dedicated_ip;
	/usr/sbin/vzctl start $ctid;
	/usr/sbin/vzctl set $ctid --diskspace {$diskspace}g:{$diskspace}g  --physpages 0:{$ram} --swappages 0:{$swap}  --nameserver 8.8.8.8 --nameserver 8.8.4.4 --userpasswd root:$password --onboot yes --cpus $cpus --save;
	/usr/sbin/vzctl set $ctid --ipadd {$assignedipv6} --save;
	");
	if ($createvps) {
		$result = "success";
	} else {
		$result = "Can't Create Container...";
	}
	return $result;

}

function XSLOVZ_TerminateAccount($params) {
	// Get dedicated IP from DB
	$result = select_query("tblhosting","",array("id"=>$params['serviceid']));
	$data = mysql_fetch_assoc($result);
	$dedicated_ip = $data["dedicatedip"];
	
    $ctid = intval($params["serviceid"]); 


    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$result = select_query("tblhosting","",array("id"=>$params['serviceid']));
	$data = mysql_fetch_assoc($result);
	$dedicated_ip = $data["dedicatedip"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");

	$destroy = ssh2_exec($connection, "
	/usr/sbin/vzctl stop $ctid;
	/usr/sbin/vzctl destroy $ctid;
	/sbin/iptables -D FORWARD -s $dedicated_ip -j ACCEPT;
	/sbin/iptables -D FORWARD -d $dedicated_ip -j ACCEPT;
	");
	
    if ($destroy) {
		$result = "success";
	} else {
		$result = "Can't Terminate Container";
	}
	return $result;

}

function XSLOVZ_SuspendAccount($params) {
    $ctid = intval($params["serviceid"]); 

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	
	$suspend = ssh2_exec($connection, "
	/usr/sbin/vzctl set $ctid --disabled yes --save;
	/usr/sbin/vzctl stop $ctid;
	");
	
    if ($suspend) {
		$result = "success";
	} else {
		$result = "Can't suspend Container";
	}
	return $result;

}

function XSLOVZ_UnsuspendAccount($params) {

    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	
	$unsuspend = ssh2_exec($connection, "
	/usr/sbin/vzctl set $ctid --disabled no --save;
	/usr/sbin/vzctl start $ctid;
	");

    if ($unsuspend) {
		$result = "success";
	} else {
		$result = "Can't unsuspend Container";
	}
	return $result;

}

function XSLOVZ_ChangePassword($params) {
	
	$password = escapeshellarg($params["password"]);
    $ctid = $params["serviceid"];
    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	
	$changepassword = ssh2_exec($connection, "
	/usr/sbin/vzctl start $ctid;
	/usr/sbin/vzctl set {$ctid} --userpasswd root:{$password} --save;
	");
	
    if ($changepassword) {
		$result = "success";
	} else {
		$result = "Can't change password";
	}
	return $result;

}

function XSLOVZ_ChangePackage($params) {

    # ** The variables listed below are passed into all module functions **
    $ctid = intval($params["serviceid"]);
	
    # Product module option settings from ConfigOptions array above
    $configname = escapeshellarg($params["configoption1"]);
    $diskspace = intval($params["configoption2"]);
    $bandwidth = intval($params["configoption3"]);
    $ram = intval($params["configoption4"])*256;
	$swap = intval($params["configoption5"])*256;
	$cpus = intval($params["configoption6"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	$changepackage = ssh2_exec($connection,"
	/usr/sbin/vzctl set $ctid --applyconfig $configname --save ;
	/usr/sbin/vzctl set $ctid --onboot yes --cpus $cpus --diskspace {$diskspace}g:{$diskspace}g --physpages 0:{$ram} --swappages 0:{$swap}  --save;
	");

    if ($changepackage) {
		$result = "success";
	} else {
		$result = "Can't change package";
	}
	return $result;

}


function XSLOVZ_reboot($params) {
    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	$reboot = ssh2_exec($connection,"/usr/sbin/vzctl restart $ctid;");

    if ($reboot) {
		$result = "success";
	} else {
		$result = "Can't Reboot VPS";
	}
	return $result;

}

function XSLOVZ_shutdown($params) {

    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	$shutdown = ssh2_exec($connection,"/usr/sbin/vzctl stop $ctid;");

    if ($shutdown) {
		$result = "success";
	} else {
		$result = "Can't Shutdown VPS";
	}
	return $result;

}

function XSLOVZ_boot($params) {

    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	$boot = ssh2_exec($connection,"/usr/sbin/vzctl start $ctid;");

    if ($boot) {
		$result = "success";
	} else {
		$result = "Can't Boot VPS";
	}
	return $result;

}

function XSLOVZ_addip($params) {

	
    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	
	// Get text from html tags
	function getTextBetweenTags($string, $tagname) {
    $pattern = "/\{$tagname ?.*}(.*){\/$tagname}/s";
    preg_match($pattern, $string, $matches);
    return $matches[1];
	}
	// get IPs and assign it to existing VPS
	$find_server = select_query("tblhosting","",array("id"=>$params['serviceid']));
	$data = mysql_fetch_assoc($find_server);
	$serverid = $data["server"];
	$assigned_ips = select_query("tblservers","",array("id"=>$serverid));
	$data = mysql_fetch_assoc($assigned_ips);
	$assignedips = getTextBetweenTags($data["assignedips"], "ip");
	if (!empty($assignedips)) {
	$assignedips = preg_split('/\n|\r/', $assignedips, -1, PREG_SPLIT_NO_EMPTY);
	foreach ($assignedips as $ip) {
	$assignedip = ssh2_exec($connection,"/usr/sbin/vzlist -a -o ip -H|grep -o -w $ip");
	stream_set_blocking($assignedip, true);
	$assignedip = trim(stream_get_contents($assignedip));
	$ip = trim($ip);
	if ( $assignedip != $ip) {
		$addip = ssh2_exec($connection,"/usr/sbin/vzctl set {$ctid} --ipadd {$ip} --save");
		break;
		}
	}	
	}
	

    if ($addip) {
		$result = "success";
	} else {
		$result = "Can't add IP";
	}
	return $result;

}

function XSLOVZ_addipv6($params) {

	
    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	
	// Get text from html tags
	function getTextBetweenTags($string, $tagname) {
    $pattern = "/\{$tagname ?.*}(.*){\/$tagname}/s";
    preg_match($pattern, $string, $matches);
    return $matches[1];
	}
	// get IPs and assign it to existing VPS
	$find_server = select_query("tblhosting","",array("id"=>$params['serviceid']));
	$data = mysql_fetch_assoc($find_server);
	$serverid = $data["server"];
	$assigned_ips = select_query("tblservers","",array("id"=>$serverid));
	$data = mysql_fetch_assoc($assigned_ips);
	$assignedips = getTextBetweenTags($data["assignedips"], "ipv6");
	if (!empty($assignedips)) {
	$assignedips = preg_split('/\n|\r/', $assignedips, -1, PREG_SPLIT_NO_EMPTY);
	foreach ($assignedips as $ip) {
	$assignedip = ssh2_exec($connection,"/usr/sbin/vzlist -a -o ip -H|grep -o -w $ip");
	stream_set_blocking($assignedip, true);
	$assignedip = trim(stream_get_contents($assignedip));
	$ip = trim($ip);
	if ( $assignedip != $ip) {
		$addip = ssh2_exec($connection,"/usr/sbin/vzctl set {$ctid} --ipadd {$ip} --save");
		break;
		}
	}	
	}
	

    if ($addip) {
		$result = "success";
	} else {
		$result = "Can't add IP";
	}
	return $result;

}

function XSLOVZ_delip($params) {

    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	// Get text from html tags
	function getTextBetweenTags($string, $tagname) {
    $pattern = "/\{$tagname ?.*}(.*){\/$tagname}/s";
    preg_match($pattern, $string, $matches);
    return $matches[1];
	}
	
	$result = select_query("tblhosting","",array("id"=>$params['serviceid']));
	$data = mysql_fetch_assoc($result);
	$assignedips = $data["assignedips"];
	if(!empty($assignedips)) {

	$assignedips = preg_split('/\n|\r/', $assignedips, -1, PREG_SPLIT_NO_EMPTY);
	foreach ($assignedips as $ip) {
	$delip = ssh2_exec($connection,"vzctl set {$ctid} --ipdel {$ip} --save;");
	sleep(10);
	}
	}
    if ($delip) {
		$result = "success";
	} else {
		$result = "Can't delete IP";
	}
	return $result;

}

function XSLOVZ_changehostname($params) {

	$domain = escapeshellarg($params["domain"]);
    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	$changehostname = ssh2_exec($connection,"/usr/sbin/vzctl set $ctid --hostname $domain --save;");

    if ($changehostname) {
		$result = "success";
	} else {
		$result = "Can't change hostname";
	}
	return $result;

}

function XSLOVZ_enableppp($params) {
    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	$ppp = ssh2_exec($connection,"
	/usr/sbin/vzctl stop $ctid;
	/usr/sbin/vzctl set $ctid --features ppp:on --save;
	/usr/sbin/vzctl start $ctid");

    if ($ppp) {
		$result = "success";
	} else {
		$result = "Can't change hostname";
	}
	return $result;

}

function XSLOVZ_disableppp($params) {
    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	$ppp = ssh2_exec($connection,"
	/usr/sbin/vzctl stop $ctid;
	/usr/sbin/vzctl set $ctid --features ppp:off --save;
	/usr/sbin/vzctl start $ctid");

    if ($ppp) {
		$result = "success";
	} else {
		$result = "Can't change hostname";
	}
	return $result;

}

function XSLOVZ_tuntap($params) {	
    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	$tuntap = ssh2_exec($connection,"
	vzctl set {$ctid} --devnodes net/tun:rw --save;
	vzctl set {$ctid} --devices c:10:200:rw --save;
	vzctl stop {$ctid};
	vzctl set {$ctid} --capability net_admin:on --save;
	vzctl start {$ctid};
	vzctl exec {$ctid} mkdir -p /dev/net;
	vzctl exec {$ctid} mknod /dev/net/tun c 10 200;
	");

    if ($tuntap) {
		$result = "success";
	} else {
		$result = "Can't add iptables rule";
	}
	return $result;

}

function XSLOVZ_disabledtuntap($params) {	
    $ctid = intval($params["serviceid"]);

    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	$tuntap = ssh2_exec($connection,"
	vzctl set {$ctid} --devnodes net/tun:none --save;
	vzctl set {$ctid} --devices c:10:200:none --save;
	vzctl stop {$ctid};
	vzctl set {$ctid} --capability net_admin:off --save;
	vzctl start {$ctid};
	");

    if ($tuntap) {
		$result = "success";
	} else {
		$result = "Can't add iptables rule";
	}
	return $result;

}

function XSLOVZ_reinstall($params) {
	XSLOVZ_TerminateAccount($params);
	XSLOVZ_CreateAccount($params);
}

function XSLOVZ_ClientAreaCustomButtonArray() {
    $buttonarray = array(
	 "Reboot Server" => "reboot",
	 "Shutdown Server" => "shutdown",
	 "Boot Server" => "boot",
	 "Enable Tun\Tap" => "tuntap",
	 "Disable Tun\Tap" => "disabletuntap",
	 "Enable PPP" => "enableppp",
	 "Disable PPP" => "disableppp",
	);
	return $buttonarray;
}

function XSLOVZ_AdminCustomButtonArray() {
    $buttonarray = array(
	 "Reboot Server" => "reboot",
	 "Shutdown Server" => "shutdown",
	 "Boot Server" => "boot",
	 "Assign IP(s)" => "addip",
	 "Assign IPv6" => "addipv6",
	 "Delete IP(s)" => "delip",
	 "Change Hostname" => "changehostname",
	 "Apply TUN/TAP" => "tuntap",
	 "Enable PPP" => "enableppp",
	 "Reinstall" => "reinstall",
	);
	return $buttonarray;
}



function XSLOVZ_UsageUpdate($params) {
	
	$serverid = $params['serverid'];
	$serverhostname = $params['serverhostname'];
	$serverip = $params['serverip'];
	$serverusername = $params['serverusername'];
	$serverpassword = $params['serverpassword'];
	$serveraccesshash = $params['serveraccesshash'];
	$serversecure = $params['serversecure'];
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	
	$result = select_query("tblhosting","",array("server"=>$serverid));
	while ($row = mysql_fetch_assoc($result)) {
		$useddiskspace = ssh2_exec($connection,"/usr/bin/du -s /var/lib/vz/private/{$row['id']} | cut -f 1");
		stream_set_blocking($useddiskspace, true);
		$diskspace = stream_get_contents($useddiskspace)/1024;
		$bandwidth = ssh2_exec($connection,"cat /var/lib/traffic/{$row['id']}.traffic");
		stream_set_blocking($bandwidth, true);
		$bandwidth = stream_get_contents($bandwidth);
		$bandwidth = round($bandwidth/1024/1024,2);
		$package = select_query("tblproducts","",array("id"=>$row['packageid']));
		$package = mysql_fetch_assoc($package);
		$disklimit = $package['configoption2']*1024;
			
		$additional_bandwidth = $params['configoptions']['Bandwidth'] * 1024;
		
		
		$bwlimit = $package['configoption3']*1024 + $additional_bandwidth;
		update_query("tblhosting",array(
         "diskusage"=>"$diskspace",
		 "disklimit"=>"$disklimit",
         "bwusage"=>"$bandwidth",
		 "bwlimit"=>"$bwlimit",
         "lastupdate"=>"now()",
        ),array("server"=>$serverid,"id"=>$row['id']));
		
		$bandwidth_percent = round($bandwidth / $bwlimit * 100, 2);
		$bandwidth_gb = round($bandwidth / 1024,2);
		$bwlimit_gb = $bwlimit / 1024;
		
		// Send Bandwidth Notification on 85% of usage
		if ($row['domainstatus'] == 'Active') {
		if ($bandwidth_percent >= 85) {
			$messagename = 'Bandwidth Usage Notification';
			$relid = $row['id'];
			$extravars = array( 'bandwidth' => $bandwidth_gb, 'bwlimit' => $bwlimit_gb, 'bandwidth_percent' => $bandwidth_percent);
			sendMessage($messagename,$relid,$extravars);
			
			}
		}
		}
}

function XSLOVZ_AdminServicesTabFields($params) {
    $ctid = intval($params["serviceid"]); 
    # Additional variables if the product/service is linked to a server
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	
	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	$current_assigned_ips = ssh2_exec($connection,"/usr/sbin/vzlist $ctid -o ip| grep -v IP_ADDR;");
	stream_set_blocking($current_assigned_ips, true);
	$current_load_average = ssh2_exec($connection,"/usr/sbin/vzlist $ctid -o laverage| grep -v LAVERAGE;");
	stream_set_blocking($current_load_average, true);
	$status = ssh2_exec($connection,"/usr/sbin/vzlist $ctid -o status| grep -v STATUS;");
	stream_set_blocking($status, true);
	
    $fieldsarray = array(
     'Current assigned IPs' => stream_get_contents($current_assigned_ips),
     'Current Load Averages' => stream_get_contents($current_load_average),
     'Status' => stream_get_contents($status),
    );
    return $fieldsarray;

}

function XSLOVZ_ClientArea($params) {

    $ctid = intval($params["serviceid"]);
    # Additional variables if the product/service is linked to a server
	$serverid = $params['serverid'];
    $server = $params["server"]; # True if linked to a server
    $serverip = $params["serverip"];
    $serverusername = $params["serverusername"];
    $serverpassword = $params["serverpassword"];
	$ram = intval($params["configoption4"]);
	$swap = intval($params["configoption5"]);

	$connection = ssh2_connect("$serverip", 22);
	ssh2_auth_password($connection, "$serverusername", "$serverpassword");
	// Getting Load Averages of running VPS.
	$current_load_average = ssh2_exec($connection,"/usr/sbin/vzlist $ctid -o laverage -H; ");
	stream_set_blocking($current_load_average, true);
	$current_load_average = stream_get_contents($current_load_average);
	// Getting Status of running VPS.
	$status = ssh2_exec($connection,"/usr/sbin/vzlist $ctid -o status -H; ");
	stream_set_blocking($status, true);
	$status = stream_get_contents($status);
	// Getting uptime of running VPS.
	$uptime = ssh2_exec($connection,"/usr/sbin/vzlist $ctid -o uptime -H; ");
	stream_set_blocking($uptime, true);
	$chars = array("d","h:","m:");
	$words = array(" Day(s) ", " Hour(s) ", " Minute(s) ");
	$uptime = stream_get_contents($uptime);
	$uptime = str_replace($chars, $words, $uptime);
	// Getting Used RAM percentage
    $usedram = ssh2_exec($connection,"/usr/sbin/vzlist $ctid -o physpages -H; ");
	stream_set_blocking($usedram, true);
	$usedram = round(stream_get_contents($usedram) / 256,2);
	$usedram_percent = round($usedram / $ram * 100,2);
	// Getting Used SWAP percentage
	$usedswap = ssh2_exec($connection,"/usr/sbin/vzlist $ctid -o swappages -H; ");
	stream_set_blocking($usedswap, true);
	$usedswap = round(stream_get_contents($usedswap) / 256,2);
	$usedswap_percent = round($usedswap / $swap * 100, 2);
	// Getting Number of Proccesses / Threads
	$numproc = ssh2_exec($connection,"/usr/sbin/vzlist $ctid -o numproc -H; ");
	stream_set_blocking($numproc, true);
	$numproc = stream_get_contents($numproc);
	// Getting Assigned IPs
	$ips = ssh2_exec($connection,"/usr/sbin/vzlist $ctid -o ip -H; ");
	stream_set_blocking($ips, true);
	$ips = stream_get_contents($ips);
	// Getting Num of CPUs
	$cpus = ssh2_exec($connection,"/usr/sbin/vzlist $ctid -o cpus -H; ");
	stream_set_blocking($cpus, true);
	$cpus = stream_get_contents($cpus);
	// Getting CPU Model
	$cpumodel = ssh2_exec($connection,"cat /proc/cpuinfo | grep \"model name\" | tail -n1 | cut -f 2 -d :");
	stream_set_blocking($cpumodel, true);
	$cpumodel = stream_get_contents($cpumodel);
	
	// Real Time Bandwidth
	$bandwidth = ssh2_exec($connection,"cat /var/lib/traffic/$ctid.traffic");
	stream_set_blocking($bandwidth, true);
	$bandwidth = stream_get_contents($bandwidth);
	$bandwidth = round($bandwidth/1024/1024,2);
	update_query("tblhosting",array("bwusage"=>"$bandwidth"),array("id"=>$ctid));
		
	$code = "
	<a class=\"btn btn-warning\" href=\"clientarea.php?action=productdetails&id={$ctid}&modop=custom&a=reboot\">Reboot</a>  <a class=\"btn btn-danger\" href=\"clientarea.php?action=productdetails&id={$ctid}&modop=custom&a=shutdown\">Shutdown</a> <a class=\"btn btn-info\" href=\"clientarea.php?action=productdetails&id={$ctid}&modop=custom&a=boot\">Boot</a> <br />

<div class=\"btn-toolbar\" style=\"display:inline-block;\">
  <div class=\"btn-group\">
    <a class=\"btn\" href=\"clientarea.php?action=productdetails&id={$ctid}&modop=custom&a=tuntap\"><i class=\"icon-ok\"></i> TUN\TAP</a>
    <a class=\"btn\" href=\"clientarea.php?action=productdetails&id={$ctid}&modop=custom&a=disabletuntap\"><i class=\"icon-remove\"></i></a>

    <a class=\"btn\" href=\"clientarea.php?action=productdetails&id={$ctid}&modop=custom&a=enableppp\"><i class=\"icon-ok\"></i> PPP Device</a>
    <a class=\"btn\" href=\"clientarea.php?action=productdetails&id={$ctid}&modop=custom&a=disableppp\"><i class=\"icon-remove\"></i></a>
  </div>
</div>
	
	</div>
	<div>
	<p><h4>VPS Technical Details</h4></p>
	<hr />
	<div class=\"col2half\">
	<h4>Status :</h4>
	<span>{$status}</span>
	</div>
	<div class=\"col2half\">
	<h4>Load Averages :</h4>
	<span>{$current_load_average}</span>
	</div>
	<div class=\"col2half\">
	<h4>Uptime :</h4>
	<span>{$uptime}</span>
	</div>
	<div class=\"col2half\">
	<h4>Processes \ Threads :</h4>
	<span>{$numproc}</span>
	</div>
	
	<div class=\"clear\"></div>
	
	<div class=\"col2half\">
    <p><h4>RAM usage:</h4> {$usedram}MB / {$ram}MB ({$usedram_percent}%)</p>
     <div class=\"usagecontainer\"><div class=\"used\" style=\"width:{$usedram_percent}%\"></div></div>
    </div>
    <div class=\"col2half\">
    <p><h4>SWAP usage:</h4> {$usedswap}MB / {$swap}MB ({$usedswap_percent}%)</p>
     <div class=\"usagecontainer\"><div class=\"used\" style=\"width:{$usedswap_percent}%\"></div></div>
    </div>
	
	<div class=\"clear\"></div>
	
	<div class=\"row\">
	<h4>IPv4 \ IPv6 :</h4>
	<span>{$ips}</span>
	</div>
	<div class=\"clear\"></div>

	<div class=\"col2half\">
	<h4>CPU Model :</h4>
	<span>{$cpumodel}</span>
	</div>

	<div class=\"col2half\">
	<h4>Number of CPUs :</h4>
	<span>{$cpus}</span>
	</div>
	";

	return $code;
	


}

?>
