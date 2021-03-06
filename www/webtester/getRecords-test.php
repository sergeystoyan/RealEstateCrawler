<?php
//ob_start();
// Displays a multi-dimensional array as a HTML unordered lists.
function displayTree($array) {
     $newline = "<br>";
     foreach($array as $key => $value) {    //cycle through each item in the array as key => value pairs
         if (is_array($value) || is_object($value)) {        //if the VALUE is an array, then
            //call it out as such, surround with brackets, and recursively call displayTree.
             $value = "Array()" . $newline . "(<ul>" . displayTree($value) . "</ul>)" . $newline;
         }
        //if value isn't an array, it must be a string. output its' key and value.
        $output .= "[$key] => " . $value . $newline;
     }
     return $output;
}

class Logger {
	public static function Write2($a=NULL, $b=NULL) {
		return;
	}
}

function utf8conv($t) {
	return iconv('LATIN1','UTF-8//TRANSLIT',$t);
}


include ('lib/db.php');

include ('/home/crawler/app/parser/value_parser.php');


$crawler=$_POST['crawler'];
$crawler_type=FALSE;
$crawler_table_prefix='XXX';
$state=$_POST['state'];

$id='primelocation_com+11734783';
$xfilter_status=trim($_POST['xfilter_status']);
$xfilter_status_nonempty=$_POST['xfilter_status_nonempty'];

$re=array();
$re_count='';
$re_response='';

function setCrawlerType() {
	global $crawler_type, $crawler_table_prefix, $crawler;
	if (!mysql_fetch_array(mysql_query("show tables like 'products_$crawler'"))) {
		$crawler_type='auction';
		$crawler_table_prefix='2';
	} else {
		$crawler_type='sale';
		$crawler_table_prefix='';
	}

}


if ($id) {
	if (preg_match("@^\d+$@i",$id)) {  //drupal nid search
		$r=mysql_fetch_assoc(mysql_query("select cid from kh_deals where nid=$id",$ddb));
		$cid=$r['cid'];
		if (!$cid) {
			$re_response="NID: $id record NOT FOUND in drupal db";
			$re_status="FAIL";
			goto send_data;
		}
		$nid=$id;
		$re_response="$nid -> $cid; ";
		$id=$cid;
	}

	$id_a=explode('+',$id);
	$crawler=$id_a[0];
	$id=$id_a[1];
	setCrawlerType();

	$wh="id ='".mysql_real_escape_string($id)."'";
	$r=mysql_fetch_assoc(mysql_query("select * from products${crawler_table_prefix}_$crawler where ".$wh));
	if ($r === FALSE) {
		$re_response.="ID: $crawler+$id record NOT FOUND";
		$re_status="FAIL";
	} else {
		$re[]=$r;
		$re_response.="ID: $crawler+$id single record LOADED";
		$re_status="OK";
	}
} else {
	setCrawlerType();
	$wh="1=1 ";
	if ($state)
		$wh.=" and _state='".mysql_real_escape_string($state)."'";
	if ($xfilter_status)
		$wh.=" and status like '%".mysql_real_escape_string($xfilter_status)."%'";
	else if ($xfilter_status_nonempty)
		$wh.=" and status<>''";

	$count=mysql_result(mysql_query("select count(*) from products${crawler_table_prefix}_$crawler where ".$wh),0);
	$re_count=$count;


	$limit=intval($_POST['limit']);
	if ($limit==0 && $count>500) {
		$re_response="LIST ($crawler".
				($state ? ",$state":'').
				($xfilter_status ? ",[st:$xfilter_status]": ($xfilter_status_nonempty?",[st:_NON-EMPTY_]":'')).
				"): CANNOT list all records when more then 500 available ($count)";
		$re_status='FAIL';
		goto send_data;
	}
	if ($count) {
		if ($_POST['type'] == 'LIST') {
			$from=intval($_POST['from']);
			if (!$limit)
				$limit=$count;
			$q=mysql_query("select * from products${crawler_table_prefix}_$crawler where ".$wh." limit ".$from.",".$limit);
			while ($r=mysql_fetch_assoc($q))
				$re[]=$r;
			$re_response="LIST ($crawler".
					($state ? ",$state":'').
					($xfilter_status ? ",[st:$xfilter_status]":($xfilter_status_nonempty?",[st:_NON-EMPTY_]":'')).
					"): records $from-".($from+$limit-1)." out of $count records LOADED";
			$re_status='OK';
		} else { //random
			for ($i=0; $i<$limit; $i++) {
				$ofs=rand(0,$count-1);
	//			echo "select * from products where ".$wh." offset ".$ofs." limit 1\n";
				$r=mysql_fetch_assoc(mysql_query("select * from products${crawler_table_prefix}_$crawler where ".$wh." limit ".$ofs.",1"));
				$re[]=$r;
			}
			$re_response="RANDOM ($crawler".
					($state ? ",$state":'').
					($xfilter_status ? ",[st:$xfilter_status]":($xfilter_status_nonempty?",[st:_NON-EMPTY_]":'')).
					"): $limit out of $count records LOADED";
			$re_status='OK';
		}
	} else {
		$re_response="($crawler".
				($state ? ",$state":'').
				($xfilter_status ? ",[st:$xfilter_status]":($xfilter_status_nonempty?",[st:_NON-EMPTY_]":'')).
				"): match was NOT FOUND";
		$re_status='FAIL';
	}
}
for ($i=0; $i<count($re); $i++) {
	$reo=array();
	$reo['headline']=$re[$i]['headline'];
	$reo['description']=$re[$i]['description'];
	if ($crawler_type == 'sale') {
		foreach (array('headline','description','price','type','status','category','agent','is_sold','features','tenure','bedroom_number','town','street','county','postcode','address','_state','crawl_time','publish_date') as $ic)
			$re[$i][$ic]=utf8conv($re[$i][$ic]);
	}
	if ($crawler_type == 'auction') {
		foreach (array('headline','description','price','type','status','features','bedroom_number','town','street','county','postcode', 'tenure','tenacy','agent','auction_date','aution_time','auction_location','leasehold_years','address','_state','crawl_time','publish_date') as $ic)
			$re[$i][$ic]=utf8conv($re[$i][$ic]);
	}
//	print_r($re); echo "\n";
	$re[$i]['headline_stripped']=	strip_tags(
						preg_replace('/(<\/[^>]+?>)(<[^>\/][^>]*?>)/', '$1 $2',$re[$i]['headline']) );
	$re[$i]['description_stripped']=strip_tags(
						preg_replace('/(<\/[^>]+?>)(<[^>\/][^>]*?>)/', '$1 $2',$re[$i]['description']));
	$re[$i]['auction_location_stripped']=strip_tags(
						preg_replace('/(<\/[^>]+?>)(<[^>\/][^>]*?>)/', '$1 $2',$re[$i]['auction_location']));
	$re[$i]['agent_stripped']=strip_tags(
						preg_replace('/(<\/[^>]+?>)(<[^>\/][^>]*?>)/', '$1 $2',$re[$i]['agent']));
	$re[$i]['address_stripped']=strip_tags(
						preg_replace('/(<\/[^>]+?>)(<[^>\/][^>]*?>)/', '$1 $2',$re[$i]['address']));
	$re[$i]['id']=$crawler.'+'.$re[$i]['id'];


	$vp= new ValueParser($reo);
	$highlight=array(	'bedroom_number' => 'BedroomNumber',
				'type' => 'Type',
				'price' => 'Price',
				'status' => 'Status',
				'tenure' => 'Tenure',
				'is_sold' => 'IsSold',
				'features' => 'Features' );
	$highlight=array(	'status' => 'Status');
	foreach ($highlight as $hk => $hv) {
		$m=array();
		$f='Parse'.$hv;
		$vp->$f($m);
		$m2=array();
		foreach ($m as $mi) {
			if (!$mi['matches'][0])
				continue;
			$text=$mi['text'];
			for ($j=0; $j<count($mi['matches']); $j++) {
				$pos= $mi['matches'][$j][1];
				$len= strlen($mi['matches'][$j][0]);


				$m2[]=	array( 'pos' => $pos, 'len' => $len );
/*				$m2[]=	htmlspecialchars(utf8conv(substr($text,0,$pos))).
					'<span class="highlight">'.
					htmlspecialchars(utf8conv(substr($text,$pos,$len))).
					'</span>'.
					htmlspecialchars(utf8conv(substr($text,$pos+$len)));
*/
			}
		}
		print_r($m2); echo "\n";
		if (count($m2)) {
			$hi_text='';
			$text_len=strlen($text);
			$hi_pos=0;
			while ($hi_pos<$text_len) {
				$hi_match=array();
				$hi_len=$text_len-$hi_pos;
				for ($m2_i=0; $m2_i<count($m2); $m2_i++) {
					$m2x=$m2[$m2_i];
					if ($hi_pos<$m2x['pos']) {
						$hi_len=min($hi_len, $m2x['pos']-$hi_pos);
					} elseif ($hi_pos<$m2x['pos']+$m2x['len']) {
						$hi_len=min($hi_len, $m2x['pos']+$m2x['len']-$hi_pos);
						$hi_match[]="h$m2_i";
					}
				}
				if (!count($hi_match)) {
					$hi_text=	$hi_text .
							htmlspecialchars(utf8conv(substr($text,$hi_pos,$hi_len)));
				} else {
					$hi_text=	$hi_text .
							'<span class="highlight '. implode(' ',$hi_match)  .' ">'.
							htmlspecialchars(utf8conv(substr($text,$hi_pos,$hi_len))).
							'</span>';
				}
				$hi_pos+=$hi_len;
			}
			$re[$i]['highlight_'.$hk]=$hi_text;
			$re[$i]['has_highlight_'.$hk]=1;
		} else {
			$re[$i]['highlight_'.$hk]='';
		}
	}
}


send_data:
//ob_end_clean();
echo json_encode(array(	'crawler_type' => $crawler_type,
			'results' => $re,
			'count' => $re_count,
			'response' => $re_response,
			'status' => $re_status,
			'from' => $from, 'limit' => $limit	));

?>