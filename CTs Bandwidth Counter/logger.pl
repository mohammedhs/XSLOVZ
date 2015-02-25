#!/usr/bin/perl
#	OpenVZ Bandwidth Counter through iptables
#	Copyright (C) 2015  Mohammed H (hussein.m@xsl.tel)
#
#    This program is free software: you can redistribute it and/or modify
#    it under the terms of the GNU General Public License as published by
#    the Free Software Foundation, either version 3 of the License, or
#    any later version.
#
#    This program is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU General Public License for more details.
#
#    You should have received a copy of the GNU General Public License
#    along with this program.  If not, see <http://www.gnu.org/licenses/>.

$ENV{PATH}='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
use Socket;
sub IsValidIP4{
         return $_[0] =~ /^[\d\.]*$/ && inet_aton($_[0]);
       }
sub IsValidIP6
       {
            return $_[0] =~ /^\s*((([0-9A-Fa-f]{1,4}:){7}([0-9A-Fa-f]{1,4}|:))|(([0-9A-Fa-f]{1,4}:){6}(:[0-9A-Fa-f]{1,4}|((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3})|:))|(([0-9A-Fa-f]{1,4}:){5}(((:[0-9A-Fa-f]{1,4}){1,2})|:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3})|:))|(([0-9A-Fa-f]{1,4}:){4}(((:[0-9A-Fa-f]{1,4}){1,3})|((:[0-9A-Fa-f]{1,4})?:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(([0-9A-Fa-f]{1,4}:){3}(((:[0-9A-Fa-f]{1,4}){1,4})|((:[0-9A-Fa-f]{1,4}){0,2}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(([0-9A-Fa-f]{1,4}:){2}(((:[0-9A-Fa-f]{1,4}){1,5})|((:[0-9A-Fa-f]{1,4}){0,3}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(([0-9A-Fa-f]{1,4}:){1}(((:[0-9A-Fa-f]{1,4}){1,6})|((:[0-9A-Fa-f]{1,4}){0,4}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(:(((:[0-9A-Fa-f]{1,4}){1,7})|((:[0-9A-Fa-f]{1,4}){0,5}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:)))(%.+)?\s*$/  ;
       }
$uptime = `cat /proc/uptime | cut -f 1 -d ' '| cut -f 1 -d '.'`;
$uptime =~ s/^\s+|\s+$//g;
	if ($uptime < 360) {
		system('iptables-restore -t filter -c < /var/lib/traffic/iptables.traffic');
		system('ip6tables-restore -t filter -c < /var/lib/traffic/iptables.traffic');
	}
$date = `date '+%d%H'`;
$date =~ s/^\s+|\s+$//g;
	if($date == '0100') {
		system('/sbin/iptables -Z FORWARD');
		system('rm -rf /var/lib/traffic/*');
		print "removed log files and zeroed iptables"
	}
else {
open(my $fh, '-|', 'vzlist -o vpsid -H') or die $!;

while (my $veid = <$fh>) {
        $veid =~ s/^\s+|\s+$//g;
        $file = "/var/lib/traffic/$veid.traffic";
        $folder = "/var/lib/traffic/";
        if (!-d $folder) {
        	system("mkdir $folder");
        }
        $vmips=`vzlist -H -o ip $veid`;
        $vmips=~ s/^\s+|\s+$//g;
        @vpsips = split / /, $vmips;
        $incomingtraffic = 0;
        $outgoingtraffic = 0;
        foreach $vpsip (@vpsips) {
        if (IsValidIP4($vpsip) ) {
	        $incomingcounter = system("iptables -S | grep -Fq 'd $vpsip'");
			if ($incomingcounter >> 8 != 0) {
			     system("iptables -A FORWARD -d $vpsip -j ACCEPT");
			}
	        $outgoingcounter = system("iptables -S | grep -Fq 's $vpsip'");
	        if ($outgoingcounter >> 8 != 0) {
	        	system("iptables -A FORWARD -s $vpsip -j ACCEPT");
	        }
        $getincomingtraffic = `iptables -v -S | grep "d $vpsip" | cut -f 7 -d ' '`;
        $getincomingtraffic =~ s/^\s+|\s+$//g;
        $incomingtraffic += $getincomingtraffic;
        $getoutgoingtraffic = `iptables -v -S | grep "s $vpsip" | cut -f 7 -d ' '`;
        $getoutgoingtraffic =~ s/^\s+|\s+$//g;
        $outgoingtraffic += $getoutgoingtraffic;
        }
        elseif(IsValidIP6($vpsip)) {
	        $incomingcounter = system("ip6tables -S | grep -Fq 'd $vpsip'");
			if ($incomingcounter >> 8 != 0) {
			     system("ip6tables -A FORWARD -d $vpsip -j ACCEPT");
			}
	        $outgoingcounter = system("ip6tables -S | grep -Fq 's $vpsip'");
	        if ($outgoingcounter >> 8 != 0) {
	        	system("ip6tables -A FORWARD -s $vpsip -j ACCEPT");
	        }
        $getincomingtraffic = `ip6tables -v -S | grep "d $vpsip" | cut -f 7 -d ' '`;
        $getincomingtraffic =~ s/^\s+|\s+$//g;
        $incomingtraffic += $getincomingtraffic;
        $getoutgoingtraffic = `ip6tables -v -S | grep "s $vpsip" | cut -f 7 -d ' '`;
        $getoutgoingtraffic =~ s/^\s+|\s+$//g;
        $outgoingtraffic += $getoutgoingtraffic;
        }
        }
        $totaltraffic = $incomingtraffic + $outgoingtraffic;
        open(my $traffictofile, '>', $file);
        print $traffictofile $totaltraffic;
        close $traffictofile;
}
if ($uptime > 360) {
	system('iptables-save -t filter -c > /var/lib/traffic/iptables.traffic');
	system('ip6tables-save -t filter -c > /var/lib/traffic/ip6tables.traffic');
}
}