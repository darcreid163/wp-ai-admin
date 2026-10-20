<?php
/** Native repository boundary gate. Intended to run before publication and on every PR. */
$root=dirname(__DIR__);
$denyPrefixes=['wp-ai-admin-cloud/','wp-ai-cloud/','legacy/wp-ai-cloud/','releases/wp-ai-cloud-'];
$denySuffixes=['.zip','.sql','.sqlite','.pem','.p12','.key','.env','.bak'];
$bad=[];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($it as $item){
 if(!$item->isFile())continue;
 $path=str_replace('\\','/',substr($item->getPathname(),strlen($root)+1));
 if(str_starts_with($path,'.git/'))continue;
 foreach($denyPrefixes as $prefix)if(str_starts_with($path,$prefix))$bad[]=$path.': server-only path';
 foreach($denySuffixes as $ext)if(str_ends_with(strtolower($path),$ext))$bad[]=$path.': forbidden artifact';
 if($item->getSize()>2000000)continue;
 $s=file_get_contents($item->getPathname());
 if($s===false)continue;
 if(preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/', $s))$bad[]=$path.': private key material';
 if(preg_match('/(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{35,}/', $s))$bad[]=$path.': probable GitHub credential';
 if(preg_match('/(?:sk_live|rk_live)_[A-Za-z0-9]{12,}/',$s))$bad[]=$path.': probable payment credential';
}
if($bad){fwrite(STDERR, implode("\n",$bad)."\n");exit(1);}
echo "PASS: repository boundary and basic credential pattern checks\n";
