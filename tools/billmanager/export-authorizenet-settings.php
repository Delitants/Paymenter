<?php
// Tokenize declarations only. Never include or execute the live payment broker.
if(count($argv)!==2)throw new RuntimeException('Expected broker file');
$wanted=array('$merchant_name'=>'api_login_id','$transaction_key'=>'transaction_key','$merchant_signature'=>'signature_key','$apiUrl'=>'api_url','$formUrl'=>'form_url');
$tokens=token_get_all(file_get_contents($argv[1]));
$tokens=array_values(array_filter($tokens,function($t){return !is_array($t)||!in_array($t[0],array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT));}));$values=array();
for($i=0;$i<count($tokens)-3;$i++){
 $t=$tokens[$i];if(!is_array($t)||$t[0]!==T_VARIABLE||!isset($wanted[$t[1]])||$tokens[$i+1]!=='=')continue;
 $v=$tokens[$i+2];if(!is_array($v)||$v[0]!==T_CONSTANT_ENCAPSED_STRING||$tokens[$i+3]!==';')throw new RuntimeException('Credential declaration is not a single literal');
 $literal=$v[1];$inner=substr($literal,1,-1);$value=$literal[0]==="'"?str_replace(array("\\'","\\\\"),array("'","\\"),$inner):stripcslashes($inner);
 if(isset($values[$wanted[$t[1]]])||$value==='')throw new RuntimeException('Ambiguous credential declaration');$values[$wanted[$t[1]]]=$value;
}
if(count($values)!==count($wanted))throw new RuntimeException('Missing merchant configuration');
if(!preg_match('/^[a-f0-9]{128}$/i',$values['signature_key']))throw new RuntimeException('Merchant signature key requires review');
echo json_encode($values);
