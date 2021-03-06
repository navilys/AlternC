<?php
/**
 $Id: m_authip.php
 ----------------------------------------------------------------------
 LICENSE

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License (GPL)
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 To read the license please visit http://www.gnu.org/copyleft/gpl.html
 ----------------------------------------------------------------------
 Original Author of file: Fufroma
 ----------------------------------------------------------------------
*/
/**
* Classe de gestion des IP authorisée
 * @group alternc
**/
class m_authip {

  /**
   * Retourne la liste des ip whitelist
   *
   * @global    m_mem   $mem
   * @return array retourne un tableau indexé des ip de l'utilisateur
   */
  function list_ip_whitelist() {
    global $mem;
    if (!$mem->checkRight()) return false;
    return $this->list_ip(true); 
  }

  /**
   * 
   * @return array
   */
  function hook_menu() {
    $obj = array(
      'title'       => _("Access security"),
      'ico'         => 'images/ip.png',
      'link'        => 'ip_main.php',
      'pos'         => 120,
     ) ;

     return $obj;
  }

  /**
   * Retourne la liste des ip spécifiées par cet utilisateur
   *
   * 
   * @global    m_mysql $db
   * @global    m_mem   $mem
   * @global int $cuid
   * @param     boolean $whitelist
   * @return    array   Retourne un tableau indexé des ip de l'utilisateur
   */
  function list_ip($whitelist=false) {
    global $db, $mem;
 
    if ($whitelist && $mem->checkRight() ) {
      $cuid=0;
    } else {
      global $cuid;
    }

    $r = array();
    $db->query("SELECT * FROM authorised_ip WHERE uid='$cuid' order by ip,subnet;");
    while ($db->next_record()) {
      $r[$db->f('id')]=$db->Record;
      if ( (checkip($db->f('ip'))   && $db->f('subnet') == 32) ||
           (checkipv6($db->f('ip')) && $db->f('subnet') == 128) ) {
        $r[$db->f('id')]['ip_human']=$db->f('ip');
      } else {
        $r[$db->f('id')]['ip_human']=$db->f('ip')."/".$db->f('subnet');
      }

    }
    return $r;
  }



  /**
   * Supprime une IP des IP de l'utilisateur
   * et supprime les droits attaché en cascade
   *
   * @param integer $id 
   * @return boolean 
   * 
   * @global    m_mysql $db
   * @global int $cuid
   * @param     int     $id     id de la ligne à supprimer
   * @return    boolean         Retourne FALSE si erreur, sinon TRUE
   */
  function ip_delete($id) {
    global $db, $cuid;
    $id=intval($id);
    
    $db->query("SELECT id FROM authorised_ip_affected where authorised_ip_id ='$id';");
    while ($db->next_record()) {
      $this->ip_affected_delete($db->f('id'));
    }
    if (! $db->query("delete from authorised_ip where id='$id' and ( uid='$cuid' or uid=0) limit 1;") ) {
      echo "query failed: ".$db->Error;
      return false;
    }
    return true;
  }

  /**
   * Liste les IP et subnet authorisés
   * pour une classe donnée
   * 
   * @global    m_mysql $db
   * @global int $cuid
   * @param     string  $s      Classe concernée
   * @return    array
   */
  function get_allowed($s) {
    global $db, $cuid;
    if (! $db->query("select ai.ip, ai.subnet, ai.infos, aia.parameters from authorised_ip ai, authorised_ip_affected aia where aia.protocol='$s' and aia.authorised_ip_id = ai.id and ai.uid='$cuid';") ) {
      echo "query failed: ".$db->Error;
      return false;
    }
    $r=Array();
    while ($db->next_record()) {
      $r[]=Array("ip"=>$db->f("ip"), "subnet"=>$db->f("subnet"), "infos"=>$db->f("infos"), "parameters"=>$db->f("parameters"));
    }
    return $r;
  }

  /**
   * 
   * @global    m_mysql $db
   * @param     string  $ip
   * @return    boolean
   */
  function is_wl($ip) {
    global $db;
    if (! $db->query("select ai.ip, ai.subnet from authorised_ip ai where ai.uid='0';") ) {
      echo "query failed: ".$db->Error;
      return false;
    }
    while ($db->next_record()) {
      if ( $this->is_in_subnet($ip, $db->f('ip'), $db->f('subnet') ) ) return true;
    }
    return false;
  }

  /**
   * Retourne si l'ip appartient au subnet.
   *
   * @param     string  $o
   * @param     string  $ip
   * @param     string  $sub
   * @return boolean
   */
   function is_in_subnet($o, $ip, $sub) {
    $o = inet_pton($o);
    $ip = inet_pton($ip);
    $sub = pow(2, $sub);
  
    if ( $o >= $ip && $o <= ($ip+$sub) ) return true;
    return false;
  }

  /**
   * Sauvegarde une IP dans les IP TOUJOURS authorisée
   *
   * @global    m_mem   $mem
   */
  function ip_save_whitelist($id, $ipsub, $infos) {
    global $mem;
    if (!$mem->checkRight()) return false;
    return $this->ip_save($id, $ipsub, $infos, 0);
  }

  /**
   * Sauvegarde une IP dans les IP authorisée
   * 
   * @global    m_mysql $db
   * @global    m_mem   $mem
   * @global int $cuid
   * @param     int     $id     id de la ligne à modifier. Si vide ou
   *                            égal à 0, alors c'est une insertion
   * @param     string  $ipsub  IP (v4 ou v6), potentiellement avec un subnet ( /24)
   * @param     string  $infos  Commentaire pour l'utilisateur
   * @param     int     $uid    Si $uid=0 et qu'on est super-admin, insertion avec uid=0
   *                            ce qui correspond a une ip toujours authorisée 
   * @return    boolean         Retourne FALSE si erreur, sinon TRUE
   * 
   */
  function ip_save($id, $ipsub, $infos, $uid=null) {
    global $db, $mem;

    // If we ask for uid=0, we have to check to be super-user
    // else, juste use global cuid;
    if ($uid === 0 && $mem->checkRight() ) {
      $cuid=0;
    } else {
      global $cuid;
    } 

    $id=intval($id);
    $infos=mysql_real_escape_string($infos);

    // Extract subnet from ipsub
    $tmp=explode('/',$ipsub);
    $ip=$tmp[0];

    // Error if $ip not an IP
    if ( ! checkip($ip) && ! checkipv6($ip) ) {
        echo "Failed : not an IP address";
        return false;
    }

    // Check the subnet, if not defined, give a /32 or a /128
    if (isset($tmp[1])) {
      $subnet=intval($tmp[1]);
    } else {
      if ( checkip($ip) ) $subnet=32;
      else $subnet=128;
    }

    // An IPv4 can't have subnet > 32
    if (checkip($ip) && $subnet > 32 ) $subnet=32;
      
    if ($id) { // Update
      $list_affected = $this->list_affected($id);
      foreach($list_affected as $k => $v) {
        $this->call_hooks("authip_on_delete", $k );    
      }
      if (! $db->query("update authorised_ip set ip='$ip', subnet='$subnet', infos='$infos' where id='$id' and uid='$cuid' ;") ) {
        echo "query failed: ".$db->Error;
        return false;
      }
      foreach($list_affected as $k => $v) {
        $this->call_hooks("authip_on_create", $k );    
      }
    } else { // Insert
      if (! $db->query("insert into authorised_ip (uid, ip, subnet, infos) values ('$cuid', '$ip', '$subnet', '$infos' );") ) {
        echo "query failed: ".$db->Error;
        return false;
      }
    }
    return true;
  }

  /**
   * Fonction appelée par Alternc lors de la suppression d'un utilisateur
   *
   * @global    int     $cuid
   * @global    m_mysql $db
   * @return    boolean         Retourne TRUE
   */
  function alternc_del_member() {
    global $cuid,$db;
    $db->query("SELECT id FROM authorised_ip WHERE uid ='$cuid';");
    while ($db->next_record()) {
      $this->ip_delete($db->f('id'));
    }
    return true;
  }


  /**
   * Analyse les classes et récupéres les informations
   * des classes voulant de la restriction IP
   *
   * @return array Retourne un tableau compliqué
   */
  function get_auth_class() {
    global $hooks;
    $authclass = $hooks->invoke('authip_class');

    // Je rajoute la class DANS l'objet parce que
    // ca m'interesse
    foreach ($authclass as $k => $v) {
	$authclass[$k]['class']=$k;
    }

    return $authclass;
  }

  /**
   * Enregistre ou modifie une affectation ip<=>ressource
   * Nota : lance des hooks sur la classe correspondante pour
   * informer de l'édition/création
   *
   * @global    m_mysql $db
   * @param     int     $authorised_ip_id   id de l'ip affecté
   * @param     string  $protocol           nom du protocole (définie dans la classe correspondante)
   * @param     string  $parameters         information propre au protocole
   * @param     int     $id                 $id présent si c'est une édition
   * @return    boolean                     Retourne FALSE si erreur, sinon TRUE
   */
  function ip_affected_save($authorised_ip_id, $protocol, $parameters, $id=null) {
    global $db;
    $authorised_ip_id=intval($authorised_ip_id);
    $protocol=mysql_real_escape_string($protocol);
    $parameters=mysql_real_escape_string($parameters);

    if ($id) {
      $id=intval($id);
      $this->call_hooks("authip_on_delete", $id );    
      if (! $db->query("update authorised_ip_affected set authorised_ip_id='$authorised_ip_id', protocol='$protocol', parameters='$parameters' where id ='$id' limit 1;") ) {
        echo "query failed: ".$db->Error;
        return false;
      }
      $this->call_hooks("authip_on_create", $id );    
    } else {
      if (! $db->query("insert into authorised_ip_affected (authorised_ip_id, protocol, parameters) values ('$authorised_ip_id', '$protocol', '$parameters');") ) {
        echo "query failed: ".$db->Error;
        return false;
      }
      $this->call_hooks("authip_on_create", mysql_insert_id() );    
    }
    return true;
  }

  /**
   * Supprime une affectation ip<=>ressource
   * Nota : lance des hooks dans la classe correspondante
   * pour informer de la suppression
   *
   * @global    m_mysql $db
   * @param     int     $id     id de la ligne à supprimer
   * @return    boolean         Retourne FALSE si erreur, sinon TRUE
   */
  function ip_affected_delete($id) {
    global $db;
    $id=intval($id);

    // Call hooks
    $this->call_hooks("authip_on_delete", $id );    

    if (! $db->query("delete from authorised_ip_affected where id='$id' limit 1;") ) {
      echo "query failed: ".$db->Error;
      return false;
    }
    return true;
  }


  /**
   * Appel les hooks demandé avec en parametres les 
   * affectationt ip<=>ressource dont l'id est en parametre
   *
   * @global    m_hooks $hooks
   * @global    m_err   $err
   * @param     string  $function       Nom de la fonction a rechercher et appeller dans les classes
   * @param     integer $affectation_id Id de l'affectation correspondante
   * @return    boolean                 Retourne TRUE
   */
  function call_hooks($function, $affectation_id) {
    global $hooks,$err;

    // On récure l'objet dont on parle
    $d = $this->list_affected();
    if (! isset($d[$affectation_id] )) {
      $err->raise('authip', _("Object not available"));
      return false;
    }

    $affectation = $d[$affectation_id];

    // On en déduis la classe qui le concerne
    $e = $this->get_auth_class();
    if (! isset($e[$affectation['protocol']])) {
      $err->raise('authip', sprintf(_("Can't identified class for the protocole %s"), $affectation['protocol']));
      return false;
    }
    $c = $e[$affectation['protocol']]['class'];

    // On appelle le hooks de cette classe
    $hooks->invoke($function, Array($affectation), Array($c) );

    return true;
  }

  /**
   * Liste les affectation ip<=>ressource d'un utilisateur
   *
   * @global    m_mysql $db
   * @global    int     $cuid
   * @param     int     $ip_id
   * @return    array           Retourne un tableau de valeurs
   */
  function list_affected($ip_id=null) {
    global $db, $cuid;

    $r = array();
    if ( is_null($ip_id) ) {
      $db->query("select aia.* from authorised_ip_affected aia, authorised_ip ai where ai.uid='$cuid' and aia.authorised_ip_id = ai.id order by protocol, parameters;");
    } else {
      $db->query("select aia.* from authorised_ip_affected aia, authorised_ip ai where ai.uid='$cuid' and aia.authorised_ip_id = '".intval($ip_id)."' order by protocol, parameters;");
    }
    while ($db->next_record()) {
      $r[$db->f('id')]=$db->Record;
    }
    return $r;
  }

}; /* Classe m_authip */

?>
