<?php
require 'vendor/autoload.php';
\ = file_get_contents('C:\\Users\\Administrator\\Downloads\\affiliate.shopee.vn.json');
\ = json_decode(\,true);
var_dump(json_last_error());
if(is_array(\) && isset(\[0]) && is_array(\[0])){
  echo 'count:'.count(\).PHP_EOL;
  print_r(array_keys(\[0]));
  \=[];
  foreach(\ as \=>\){
    if(is_array(\) && isset(\['domain'])) \[\['domain']] = true;
    if(\>5) break;
  }
  print_r(array_keys(\));
}

