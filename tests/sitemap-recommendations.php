<?php
$host='hexaprwire.com';$n=0;
function home_url($p='/'){global $host;return 'https://'.$host.$p;}
function wp_parse_url($u,$c=-1){return parse_url($u,$c);}
require dirname(__DIR__).'/lib/hexa-wordpress-plugin-core/src/CoreContracts/ModuleInterface.php';
require dirname(__DIR__).'/src/Seo/SitemapRecommendations.php';
require dirname(__DIR__).'/src/Bootstrap/PluginMetadata.php';
use HexaPrWire\Core\Seo\SitemapRecommendations as R;
function check($v,$m){global $n;++$n;if(!$v)throw new RuntimeException($m);}
$rows=R::recommend([]);
check(count($rows)===6,'Six required content types');
foreach(['press-release','publication','article','organization','resource','product'] as $name){
 $row=$rows['post_type_'.$name];
 check($row['recommended']===true && $row['recommended_by']===['Hexa PR Wire Core'],'Correct recommendation owner');
 check($row['available']===false,'Missing content type remains visible as unavailable');
}
$rows['post_type_publication']['available']=true;$rows['post_type_publication']['current']=false;
$rows=R::recommend($rows);check($rows['post_type_publication']['current']===false,'Recommendations never enable Rank Math');
$host='other.test';check(R::recommend([])===[],'No company-wide policy');
$main=file_get_contents(dirname(__DIR__).'/hexa-pr-wire-core.php');
check(str_contains($main,'Version: '.\HexaPrWire\Core\Bootstrap\PluginMetadata::VERSION),'Metadata version matches header');
check(str_contains($main,'lib/hexa-wordpress-plugin-core/bootstrap.php'),'One canonical bundle path');
echo "$n PR Wire sitemap assertions passed\n";
