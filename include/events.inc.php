<?php
defined('BATCH_DOWNLOAD_PATH') or die('Hacking attempt!');

# this file contains all functions directly called by the triggers #

/* define page section from url */
function batch_download_section_init()
{
  global $tokens, $page, $conf;
  
  if ($tokens[0] == 'download')
  {
    if (check_download_access() === false) access_denied();
    
    add_event_handler('loc_begin_page_header', 'batch_download_page_header');
    
    $page['section'] = 'download';
    $page['section_title'] = '<a href="'.get_absolute_root_url().'">'.l10n('Home').'</a>'.$conf['level_separator'].l10n('Batch Downloader').$conf['level_separator'];
    $page['title'] = l10n('Batch Downloader');
    
    switch (@$tokens[1])
    {
      case 'init_zip':
        $page['sub_section'] = 'init_zip';
        $page['section_title'].= l10n('Generate ZIP');
        break;
      case 'view':
        $page['sub_section'] = 'view';
        $page['section_title'].= l10n('Edit the set');
        break;
      default:
        redirect('index.php');
    }
  }
}

function batch_download_page_header()
{
  global $page;
  $page['body_id'] = 'theBatchDownloader';
}

/* download section */
function batch_download_page() 
{
  global $page;

  if (isset($page['section']) and $page['section'] == 'download')
  {
    include(BATCH_DOWNLOAD_PATH . '/include/download.inc.php');
  }
}


/* add buttons on thumbnails list */
function batch_download_index_button()
{
  global $page, $template, $user, $conf;
  
  // check accesses
  if ( !count($page['items']) or !isset($page['section']) ) return;
  
  if (check_download_access() === false) return;
  
  switch ($page['section'])
  {
  case 'categories':
    if (!isset($page['category']) && !isset($page['chronology_field'])) return; // don't download the full gallery in flat mode !
    
    if (!in_array('categories', $conf['batch_download']['what'])) return;
    break;
    
  case 'collections':
    if (!in_array('collections', $conf['batch_download']['what'])) return;
    break;
    
  default:
    if (!in_array('specials', $conf['batch_download']['what'])) return;
  }
  
  
  // download the set
  if ( isset($_GET['action']) and $_GET['action']=='advdown_set' )
  {
    $set = get_set_info_from_page();
    
    if ($set !== false)
    {
      $BatchDownloader = new BatchDownloader('new', $page['items'], $set['type'], $set['id'], $set['size']);
      
      if ($BatchDownloader->getParam('nb_images') != 0)
      {
        // if we plan only one zip with less elements than 'max_elements', the download starts immediately
        if (
          $BatchDownloader->getParam('nb_images') <= $conf['batch_download']['max_elements']
          and $BatchDownloader->getParam('size') == 'original'
          and $BatchDownloader->getEstimatedArchiveNumber() == 1
        )
        {
          $BatchDownloader->createNextArchive(true); // make sure we have only one zip, even if 'max_size' is exceeded
          
          $u_download = get_root_url().BATCH_DOWNLOAD_PATH . 'download.php?set_id='.$BatchDownloader->getParam('id').'&amp;zip=1';
          
          $null = null;
          $template->block_footer_script(null, 'setTimeout("document.location.href = \''.$u_download.'\';", 1000);', $null, $null);
          
          $page['infos'][] = sprintf(l10n('The archive is downloading, if the download doesn\'t start automatically please <a href="%s">click here</a>'), $u_download);
        }
        // otherwise we go to summary page
        else
        {
          redirect(add_url_params(BATCH_DOWNLOAD_PUBLIC . 'init_zip', array('set_id'=>$BatchDownloader->getParam('id'))));
        }
      }
      else
      {
        $BatchDownloader->delete();
        unset($BatchDownloader);
        
        $page['errors'][] = sprintf(l10n('Sorry, there is nothing to download. Some files may have been excluded because of <i title="Authorized types are : %s">filetype restrictions</i>.'), implode(', ', $conf['batch_download']['allowed_ext']));
      }
    }
  }
  
  if ($page['section'] == 'collections')
  {
    $url = $_SERVER['REQUEST_URI'];
  }
  else
  {
    $url = duplicate_index_url(array(), array('action'));
  }
  
  $url = add_url_params($url, array('action'=>'advdown_set', 'down_size'=>''));
  
  // toolbar button
  $template->set_filename('batchdwn_button', realpath(BATCH_DOWNLOAD_PATH.'template/download_button.tpl'));
  $template->assign(array(
    'BATCH_DOWNLOAD_PATH' => BATCH_DOWNLOAD_PATH,
    'BATCH_DWN_COUNT' => count($page['items']),
    'BATCH_DWN_URL' => $url,
    ));
  
  foreach (ImageStdParams::get_defined_type_map() as $params)
  {
    $template->append(
      'BATCH_DOWNLOAD_SIZES',
      array(
        'TYPE' => $params->type,
        'DISPLAY' => l10n($params->type),
        'SIZE' => $params->sizing->ideal_size[0].' x '.$params->sizing->ideal_size[1],
        )
      );
  }
  $template->append(
    'BATCH_DOWNLOAD_SIZES',
    array(
      'TYPE' => 'original',
      'DISPLAY' => l10n('Original'),
      'SIZE' => null,
      )
    );
    
  $button = $template->parse('batchdwn_button', true);
  $template->add_index_button($button, 50);
  $template->concat('COLLECTION_ACTIONS', $button);
}


/* menu block */
function batch_download_add_menublock($menu_ref_arr)
{
  global $user;
  
  $menu = &$menu_ref_arr[0];
  if ($menu->get_id() != 'menubar') return;
  
  if (check_download_access() === false) return;
  
  $query = '
SELECT id
  FROM '.BATCH_DOWNLOAD_TSETS.'
  WHERE
    user_id = '.$user['id'].'
    AND status != "done"
  LIMIT 1
;';
  $result = pwg_query($query);
  if (!pwg_db_num_rows($result)) return;
  
  $menu->register_block(new RegisteredBlock('mbBatchDownloader', l10n('Batch Downloader'), 'BatchDownloader'));
}

function batch_download_applymenu($menu_ref_arr)
{
  global $template, $conf, $user;
  
  $menu = &$menu_ref_arr[0];
  $block = $menu->get_block('mbBatchDownloader');
  
  if ($block != null)
  {
    $query = '
SELECT id 
  FROM '.BATCH_DOWNLOAD_TSETS.'
  WHERE
    user_id = '.$user['id'].'
    AND status != "done"
;';
    $sets = array_from_query($query, 'id');
    
    $data = array();
    foreach ($sets as $set_id)
    {
      $BatchDownloader = new BatchDownloader($set_id);
      $set = $BatchDownloader->getSetInfo();
      
      array_push($data, array(
        'URL' => add_url_params(BATCH_DOWNLOAD_PUBLIC . 'init_zip', array('set_id'=>$BatchDownloader->getParam('id'))),
        'TITLE' => str_replace('"', "'", strip_tags($set['COMMENT'])),
        'NAME' => $set['sNAME'],
        'COUNT' => $set['NB_IMAGES'],
        ));
    }
    
    $template->set_template_dir(BATCH_DOWNLOAD_PATH . 'template/');
    $block->set_title(l10n('Downloads'));
    $block->template = 'menublock_batch_down.tpl';
    $block->data = $data;
  }
}


/* archives and databse cleanup */
function batch_download_clean()
{
  global $conf;
  
  // we only search for old downloads every hour, nevermind which user is connected
  if ($conf['batch_download']['last_clean'] > time() - 3600) return;
  
  $conf['batch_download']['last_clean'] = time();
  conf_update_param('batch_download', serialize($conf['batch_download']));
  
  // set old sets as done and clean images table
  $query = '
DELETE i
  FROM '.BATCH_DOWNLOAD_TIMAGES.' AS i
    INNER JOIN '.BATCH_DOWNLOAD_TSETS.' AS s
    ON i.set_id = s.id
  WHERE
    status != "done" AND
    date_creation < DATE_SUB(NOW(), INTERVAL '.$conf['batch_download']['archive_timeout'].' HOUR)
;';
  pwg_query($query);
  
  $query = '
UPDATE '.BATCH_DOWNLOAD_TSETS.'
  SET status = "done"
  WHERE 
    status != "done" AND
    date_creation < DATE_SUB(NOW(), INTERVAL '.$conf['batch_download']['archive_timeout'].' HOUR)
;';
  pwg_query($query);
  
  // remove old archives
  $zips = glob(BATCH_DOWNLOAD_LOCAL . 'u-*/*.zip');
  
  if (is_array($zips))
  {
    foreach ($zips as $zip)
    {
      if (filemtime($zip) < time()-$conf['batch_download']['archive_timeout']*3600)
      {
        unlink($zip);
      }
    }
  }
}

?>