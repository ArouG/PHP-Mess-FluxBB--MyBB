<?php       
/**************************************************
 *  transmess.php - objectif = transférer les messages d'une base FluxBB vers une base myBB après que la dernière ait été 'remplie'  (après MERGE SYSTEM)
 *              conditions préalables : la base in.sqlite correspond à la base FluxBB (sqlite3), la base out_sqlite à la base MyBB (sqlite3)
 *              le préfixe de la base out.sqlite sera fourni en paramètre : ?p=<prefix>
 *               
 *  1 - Sorry for my english spoken,
 *  2 - the purpose of transfert.php is to empty an SQLITE 3 database for MyBB, named out.sqlite, from an SQLITE 3 database named in.sqlite from FluxBB 
 *  3 - the database named in.sqlite is the result of transfert.php (old 'out.sqlite' Oô)
 *  4 - the new out.sqlite is the database created with Merge System of MyBB (the table users must be full)
 *  5 - if some users are deleted during Merge System, of course, transmess can't resurrect their message box :D
 *              Auteur : françois DANTGNY 23/09/2014 
 **************************************************/                           
ignore_user_abort(TRUE);
error_reporting(E_ERROR | E_WARNING | E_PARSE); 
set_time_limit(0);    
ini_set("memory_limit" , -1);         

// http://ru2.php.net/manual/en/function.register-shutdown-function.php
function shutdown()                                                             // informe l'utilisateur des 2 erreurs principales   
{                                                                               // if bugs, tell user what is the error (2 usuals errors)   
    $gle=error_get_last();                    
    if ((!is_null($gle)) && (substr($gle['message'],0,13)=='Out of memory')) {
            echo "Desole : probleme de memoire | Sorry : troubles while allocating memory !", PHP_EOL;
    }    
}
register_shutdown_function('shutdown');                                         // utilise procédure shutdown comme fonction a exécuter avant de quitter sur erreur
                                                                                // use our function shutdown to be executed before leaving on error
$name_in='in.sqlite';                                                           // nom BdD entrée sqlite2 - name of database input sqlite 2
$name_out='out.sqlite';                                                         // nom BdD sortie sqlite3 - name of database output sqlite 3
    
$pref='';
if ( isset($_GET['p'])){
    $pref=$_GET['p'];
} 

if ((!file_exists($name_out)) || (!file_exists($name_in))){                     // on sort sur un message d'erreur
    exit("Il manque une (deux ?) base(s) | One database (more ?) is missing.<br>");
}


function RetHex($p){                                                                            
    $Hex=array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f');
    $v=(int)$p;
    $min=$v % 16;
    $max=($v-$min) / 16;
    $result=$Hex[$max].$Hex[$min];
    return $result;
}

$in = NEW PDO('sqlite:in.sqlite');                                              // la base FluxBB
if ($in){
    $out = NEW PDO('sqlite:out.sqlite');                                        // la base MyBB 
    if ($out){
        $out->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );   
        $in->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );  
        
        $bid=$in->exec("BEGIN TRANSACTION"); 
        $sqlinus="SELECT id,username FROM users";
        $stmi1=$in->prepare($sqlinus);
        $stmi1->execute();
        $inusers=$stmi1->fetchAll(PDO::FETCH_ASSOC);         
        $bid=$in->exec("COMMIT");               
        //for ($k=0; $k<count($inusers); $k++) echo "inusers ; ".$k." : ".$inusers[$k]['id'].", new_id=".$inusers[$k]['username']."<br>";   
                
        $bid=$out->exec("BEGIN TRANSACTION");  
        $sqlous="SELECT uid,username FROM ".$pref."users";
        $stous=$out->prepare($sqlous);
        $stous->execute();
        $ousers=$stous->fetchAll(PDO::FETCH_ASSOC);  
        //for ($k=0; $k<count($ousers); $k++) echo "ousers ; ".$k." : ".$ousers[$k]['uid'].", new_id=".$ousers[$k]['username']."<br>"; 
        
        $oid=array();
        for ($i=0; $i<count($ousers); $i++){
            $oid[$ousers[$i]['username']]=$ousers[$i]['uid'];
        }                                   
        //foreach ($oid as $key => $value) echo "oid[".$key."]=".$value."<br>"; 
        unset($ousers);
        $nid=array_keys($oid);
        //var_dump($nid);
        
        $Ius=array();
        for ($j=0; $j<count($inusers); $j++){
            if (in_array($inusers[$j]['username'],$nid)){
                $Ius[$inusers[$j]['id']]=array($inusers[$j]['username'],$oid[$inusers[$j]['username']]);    
                //echo "Ius -- id=".$j.", ".$inusers[$j]['username']." : nid=".$oid[$inusers[$j]['username']]."<br>";
            } else {
                $Ius[$inusers[$j]['id']]=array($inusers[$j]['username'],0); 
            }
        }
        //var_dump($Ius); echo "<br>";
        //for ($k=1; $k<count($Ius)+1; $k++) echo $k." : ".$Ius[$k][0].", new_id=".$Ius[$k][1]."<br>";                                               
        
        $sqlinmes="SELECT subject AS S, message AS M, sender_id AS Sid, posted AS P, receiver_id AS Rid, sender_ip AS Sip FROM messages GROUP BY sender_id, posted ORDER BY posted";
        $stmi2=$in->prepare($sqlinmes); 
        $stmi2->execute();  
        $mess=$stmi2->fetchAll(PDO::FETCH_ASSOC); 
        
        $sqins="INSERT INTO ".$pref."privatemessages (uid, toid, fromid, recipients, folder, subject, message, dateline, ipaddress) VALUES(?,?,?,?,?,?,?,?,?)";
        $stins=$out->prepare($sqins);
        
        for ($i=0; $i<count($mess); $i++){  
               
            // news recipients Id
            $Rec=array();
            $RecString=array();             
            $tab=explode(",",$mess[$i]['Rid']);
            for ($k=0; $k<count($tab); $k++){
                $TId=(int)$tab[$k];
                $Rec[]=$Ius[$TId][1];
                $RecString[]=trim($Ius[$TId][1].'');
            }                                  
                                                  
            $OldIdSend=(int)$mess[$i]['Sid'];
            $NewIdSend=$Ius[$OldIdSend][1];    
            
            // build the chain of serialize : 
            $ch='a:1:{s:2:"to";a:'.count($RecString).':{';
            for ($k=0; $k<count($RecString); $k++) {
                if ($RecString[$k] != $NewIdSend) $ch=$ch.'i:'.$k.';s:'.strlen($RecString[$k]).':"'.$RecString[$k].'";';
            }    
            $ch = $ch."}}";
            $recipients=$ch;
            
            //buid the IPadress
            $tabi=explode('.',$mess[$i]['Sip']);
            $ipaddress="X'".RetHex($tabi[0]).RetHex($tabi[1]).RetHex($tabi[2]).RetHex($tabi[3]);
            
            $dateline=$mess[$i]['P'];
            $subject=$mess[$i]['S'];  
            $message=$mess[$i]['M'];
            
            // $fromid
            $fromid=$NewIdSend;
            
            //$toid, $uid, $folder
            for ($k=0; $k<count($Rec); $k++){
                $toid=$Rec[$k];               
                $uid=$Rec[$k];
                $folder=1;
                if ($Rec[$k]==$NewIdSend){
                    $folder=2;
                    if (count($Rec)>2) {
                        $toid=0; 
                    } else {
                        $toid=$Rec[1-$k];
                    }    
                }
                $stins->execute(array($uid,$toid,$fromid,$recipients,$folder,$subject,$message,$dateline,$ipaddress));
            }            
        }                                
        $bid=$out->exec("COMMIT");        
    } else {                                                                        
        exit("Probleme dans la base sortie | Trouble in the output database<br>");  // Message d'erreur si problème d'ouverture table SQLITE 3 et bye !!
    }
    $out=null;     
} else {                                                                      
    exit("Probleme dans la base entre | Trouble in the input database<br>");  // Message d'erreur si problème d'ouverture table SQLITE 3 et bye !!
}                                                                                  // Error message if trouble in opening output database SQLITE 3 ... and bye !
$out=null;
echo "Tout est impec' ! | All is good !<br>";

?>
