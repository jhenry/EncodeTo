<?php

class EncodeTo extends PluginAbstract
{
  /**
   * @var string Name of plugin
   */
  public $name = 'EncodeTo';

  /**
   * @var string Description of plugin
   */
  public $description = 'Add support for additional encoding types (such as 720p) for uploaded media, and creation of SMIL for adaptive bitrate streaming. Based on work by Wes Wright.';

  /**
   * @var string Name of plugin author
   */
  public $author = 'Justin Henry';

  /**
   * @var string URL to plugin's website
   */
  public $url = 'https://uvm.edu/~jhenry/';

  /**
   * @var string Current version of plugin
   */
  public $version = '0.0.1';
  /**
   * Performs install operations for plugin. Called when user clicks install
   * plugin in admin panel.
   *
   */
  public function install()
  {
    Settings::set('encodeto_720p_options', '-acodec aac -b:a 128k -ac 2 -ar 44100  -af "aresample=first_pts=0" -pix_fmt yuv420p -vsync 1 -sn -vcodec libx264 -r 29.970 -g 60 -b:v: 1024k -vf "scale=min(1280\,trunc(iw/2)*2):trunc(ow/a/2)*2" -threads 0 -maxrate 3000k -bufsize 3000k -preset slower -profile:v high -tune film -sc_threshold 0 -map_metadata -1 -f mp4 -y');
    Settings::set('encodeto_1080p_options', '-acodec aac -b:a 128k -ac 2 -ar 44100  -af "aresample=first_pts=0" -pix_fmt yuv420p -vsync 1 -sn -vcodec libx264 -r 29.970 -g 60 -b:v: 3400k -vf "scale=min(1920\,trunc(iw/2)*2):trunc(ow/a/2)*2" -threads 0 -maxrate 3000k -bufsize 3000k -preset slower -profile:v high -tune film -sc_threshold 0 -map_metadata -1 -f mp4 -y');
  }

  /**
   * Performs uninstall operations for plugin. Called when user clicks
   * uninstall plugin in admin panel and prior to files being removed.
   *
   */
  public function uninstall()
  {
    Settings::remove('encodeto_720p_options');
    Settings::remove('encodeto_1080p_options');
  }

  /**
   * Attaches plugin methods to hooks in code base
   */
  public function load()
  {
    Plugin::attachEvent('encoder.after.thumbnail', array(__CLASS__, 'hd_encode'));
    Plugin::attachEvent('encoder.after.thumbnail', array(__CLASS__, 'createSMIL'));
    Plugin::attachEvent('myvideos.start', array(__CLASS__, 'delete'));
  }

  /**
   * Encode an HD version of the video.
   */
  public static function hd_encode($video)
  {

    if (!EncodeTo::isAudio($video)) {
      $config = Registry::get('config');
      $config->debugConversion ? App::log(CONVERSION_LOG, "\n$type Converter Called For Video: $video->videoId") : null;
      EncodeTo::runEncoder($video, 'H.264 720p', '/HD720/', Settings::get('encodeto_720p_options'));
      EncodeTo::runEncoder($video, '1080p', '/1080p/', Settings::get('encodeto_1080p_options'));
    }
  }

  /**
   * Encode to a specific format
   * 
   */
  private static function runEncoder($video, $label, $dir, $options)
  {

    if (class_exists('Wowza')) {
      Filesystem::createDir(UPLOAD_PATH . $dir);
    }

    $encoderPaths = EncodeTo::getEncoderPaths($video);
    $HDPaths = EncodeTo::getHDPaths($video, $encoderPaths, $dir);
    EncodeTo::encode($video, $label, $HDPaths, $options);
    EncodeTo::validateFileCreation($HDPaths['tempFilePath'], $video, "temp $label");
    EncodeTo::shiftMoovAtom($HDPaths, $video, $label);
    EncodeTo::cleanup($HDPaths, $video, $label);
  }

  /**
   * Get Video object based on uploaded video in _SESSION
   * 
   */
  private static function getVideoToEncode()
  {
    $videoMapper = new VideoMapper();

    if (isset($_SESSION['upload']->videoId)) {
      $video_id = $_SESSION['upload']->videoId;
      $video = $videoMapper->getVideoById($video_id);
      return $video;
    }
  }

  /**
   * Checks to see if this is an audio file and that audio is allowed.
   * @param Video $video The video object to inspect.
   * @return bool True if audio's allowed and it's an audio file.
   */
  private static function isAudio(Video $video) 
  {
    if( class_exists( 'EnableAudio' ) ) {
      $audioFormats = json_decode(Settings::get('enable_audio_formats'));
      if( in_array($video->originalExtension, $audioFormats) ) {
        return true;
      }
    }
    return false;
  }


  /**
   * Encode to HD720p 
   * 
   * @param Video $video Video object
   * @param string $type type of file, i.e. temp, final, HD, mp3, etc. 
   */
  public static function encode($video, $label, $paths, $options)
  {
    $config = Registry::get('config');

    extract($paths);

    // Build the encoder command.
    $config->debugConversion ? App::log(CONVERSION_LOG, "\nPreparing for $label Encoding...") : null;
    $command = "$ffmpegPath -i $rawVideo " . Settings::get('encodeto_720p_options') . " $tempFilePath >> $debugLogPath 2>&1";

    EncodeTo::debugLog("$label Encoding", $debugLogPath, $command);

    // Execute encoding command
    exec($command);
  }

  /**
   * Set HD720 encoder paths from configs and filenames.
   * 
   * @param array $encoderPaths keyed array of path variables
   * @param Video $video Video object
   */
  public static function getHDPaths($video, $encoderPaths = [], $HdDir = "/HD720/")
  {
    $baseHDPath = UPLOAD_PATH . $HdDir;
    $encoderPaths["rawVideo"] = UPLOAD_PATH . '/temp/' . $video->filename . '.' . $video->originalExtension;
    $encoderPaths["tempFilePath"] = $baseHDPath . $video->filename . '_temp.mp4';
    $encoderPaths["filePath"] = $baseHDPath . $video->filename . '.mp4';
    $encoderPaths["smilPath"] = UPLOAD_PATH . '/' . $video->filename . '.smil';

    return $encoderPaths;
  }


  /**
   * Shift Moov atom for faster streaming start times.
   * 
   * @param array $configs array of config variables. 
   * @param Video $video Video object
   * @param string $type type of file, i.e. temp, final, HD, mp3, etc. 
   */
  public static function shiftMoovAtom($encoderPaths, $video, $type)
  {

    extract($encoderPaths);

    // Debug Log
    $config = Registry::get('config');
    $config->debugConversion ? App::log(CONVERSION_LOG, "\nShifting moov atom on $type video...") : null;

    // Prepare shift moov atom command
    $shiftMoovAtomCommand = "$qt_faststart_path $tempFilePath $filePath >> $debugLogPath 2>&1";

    EncodeTo::debugLog("$type Shift Moov Atom", $debugLogPath, $shiftMoovAtomCommand);

    // Execute shift moov atom command
    exec($shiftMoovAtomCommand);

    EncodeTo::validateFileCreation($filePath, $video, "final $type");
  }

  /**
   * Check to see if the file was created and output logs if not.
   * 
   * @param string $path path to file
   * @param Video $video Video object
   * @param string $type type of file, i.e. temp, final, HD, mp3, etc. 
   * TODO: log out exception
   */
  public static function validateFileCreation($path, $video, $type)
  {
    $config = Registry::get('config');
    $config->debugConversion ? App::log(CONVERSION_LOG, "Verifying $type file was created...") : null;

    // Verify file was created successfully
    if (!file_exists($path) || filesize($path) < 1024 * 5) {
      throw new Exception("The $type file  for user $video->userId $user->username was not created. The id of the video is: $video->videoId $video->title");
    }
  }

  /**
   * Set encoder paths from configs and filenames.
   * 
   * @param Video $video Video object
   */
  public static function getEncoderPaths($video)
  {
    $ffmpegPath = Settings::get('ffmpeg');
    $qt_faststart_path = Settings::get('qtfaststart');
    $debugLogPath = LOG . '/' . $video->filename . '.log';
    $rawVideo = UPLOAD_PATH . '/temp/' . $video->filename . '.' . $video->originalExtension;

    return compact('ffmpegPath', 'qt_faststart_path', 'debugLogPath', 'rawVideo');
  }

  /**
   * Check permissions on transcoding binaries
   * 
   * @param string $label label/type of binary (i.e. FFMPEG vs qt-faststart).
   * @param string $path path to binary 
   * 
   */
  public static function checkTranscoderPermissions($label, $path)
  {
    $config = Registry::get('config');
    $config->debugConversion ? App::log(CONVERSION_LOG, "\nChecking $label permissions...") : null;
    if (strpos($path, DOC_ROOT) !== false && Filesystem::getPermissions($path) != '0777') {
      try {
        Filesystem::setPermissions($path, 0777);
      } catch (Exception $e) {
        throw new Exception("Unable to update permissions for $label. Please make sure $path has 777 executeable permissions.\n\nAdditional information: " . $e->getMessage());
      }
    }
  }

  /**
   * Clean up temp files and/or logs
   * 
   */
  public static function cleanup($encoderPaths, $video, $type)
  {
    $config = Registry::get('config');
    extract($encoderPaths);
    try {
      // Delete pre-faststart files
      $config->debugConversion ? App::log(CONVERSION_LOG, "Deleting temp file for $type video...") : null;
      Filesystem::delete($tempFilePath);

    } catch (Exception $e) {
      App::alert("Error During $type Video Encoding Cleanup for video: $video->videoId", $e->getMessage());
      App::log(CONVERSION_LOG, $e->getMessage());
    }
  }

  
  /**
   * Delete the encoded content if the rest of the video is being deleted.
   * 
   */
  public static function delete()
  {
    if (!empty($_GET['vid'])) {
      $authService = new AuthService();
      $user = $authService->getAuthUser();
      if ($user) 
      {
        $videoMapper = new VideoMapper();
        $video = $videoMapper->getVideoByCustom(array(
              'user_id' => $user->userId,
              'video_id' => $_GET['vid']
              ));
        if ($video) {

          $HD720Paths = EncodeTo::getHDPaths($video);
          EncodeTo::deleteHDVideo($video, $HD720Paths);

          $HD1080Paths = EncodeTo::getHDPaths($video, array(), '/1080p/');
          EncodeTo::deleteHDVideo($video, $HD1080Paths);

          // Delete SMIL
          EncodeTo::deleteSMIL($video, $HD1080Paths['smilPath']);
        }
      }
    }

  }

  /**
   * Delete a hd video
   * 
   */
  public static function deleteHDVideo($video, $HDPaths)
  {
    extract($HDPaths);
    try {
      if (file_exists($filePath))
      {
        // Delete the file
        Filesystem::delete($filePath);
      }

    } catch (Exception $e) {
      App::alert("Error During HD Video Deletion for video: $video->videoId", $e->getMessage());
    }

  }

  /**
   * Delete the smil file 
   * 
   */
  public static function deleteSMIL($video, $smilPath)
  {
    try {
      if (file_exists($smilPath))
      {
        // Delete the file
        Filesystem::delete($smilPath);
      }

    } catch (Exception $e) {
      App::alert("Error deleting SMIL for video: $video->videoId", $e->getMessage());
    }

  }

  /**
   * Format and output log info.
   * 
   * @param string $label label/type of log.
   * @param string $message log content
   * 
   */
  public static function debugLog($logType, $logPath, $command)
  {
    $config = Registry::get('config');
    $logMessage = "\n\n\n\n==================================================================\n";
    $logMessage .= "$logType\n";
    $logMessage .= "==================================================================\n\n";
    $logMessage .= "$logType Command: $command\n\n";
    $logMessage .= "$logType Output:\n\n";
    $config->debugConversion ? App::log(CONVERSION_LOG, "$logType Command: " . $command) : null;
    App::log($logPath, $logMessage);
  }



  /**
   * Create SMIL file.
   * 
   * @param array $smilPath path to smil file
   * @param Video $video Video object
   */
  public static function createSMIL($video)
  {
    $smilPath = UPLOAD_PATH . '/' . $video->filename . '.smil';
    $config = Registry::get('config');
    $config->debugConversion ? App::log(CONVERSION_LOG, "\nCreating SMIL file at $smilPath") : null;
    $content = include 'smil.php'; 
    try{
      Filesystem::create($smilPath);
      Filesystem::write($smilPath,$content, false);
    } catch (Exception $e) {
      $config->debugConversion ? App::log(CONVERSION_LOG, "\nERROR - Problem creating SMIL file at $smilPath: $e") : null;
    }
  }

/**
   * Outputs the settings page HTML and handles form posts on the plugin's
   * settings page.
   */
  public function settings()
  {
    $data = array();
    $errors = array();
    $message = null;

    // Retrieve settings from database
    $data['encodeto_720p_options'] = Settings::get('encodeto_720p_options');
    $data['encodeto_1080p_options'] = Settings::get('encodeto_1080p_options');

    // Handle form if submitted
    if (isset($_POST['submitted'])) {
      // Validate form nonce token and submission speed
      $is_valid_form = Stats::_validate_form_nonce();

      if( $is_valid_form ){
        if( !empty($_POST['encodeto_720p_options']) ) {
          $data['encodeto_720p_options'] = $_POST['encodeto_720p_options'];
        } else {
          $errors['encodeto_720p_options'] = "Encoder command options cannot be empty: " . $_POST['encodeto_720p_options'] . ". ";
        }

        if( !empty($_POST['encodeto_1080p_options']) ) {
          $data['encodeto_1080p_options'] = $_POST['encodeto_1080p_options'];
        } else {
          $errors['encodeto_1080p_options'] = "Encoder command options cannot be empty: " . $_POST['encodeto_1080p_options'] . ". ";
        }

      }
      else {
        $errors['session'] = 'Expired or invalid session';
      }

      // Error check and update data
      EncodeTo::_handle_settings_form($data, $errors);

    }
    // Generate new form nonce
    $formNonce = md5(uniqid(rand(), true));
    $_SESSION['formNonce'] = $formNonce;
    $_SESSION['formTime'] = time();


    // Display form
    include(dirname(__FILE__) . '/settings_form.php');
  }

  /**
   * Check for form errors and save settings
   * 
   */
  private static function _handle_settings_form($data, $errors){
    if (empty($errors)) {
      foreach ($data as $key => $value) {
        Settings::set($key, $value);
      }
      $message = 'Settings have been updated.';
      $message_type = 'alert-success';
    } else {
      $message = 'The following errors were found. Please correct them and try again.';
      $message .= '<br /><br /> - ' . implode('<br /> - ', $errors);
      $message_type = 'alert-danger';
    }
  }

  /**
   * Validate settings form nonce token and submission speed
   * 
   */
  private static function _validate_form_nonce(){
    if (
        !empty($_POST['nonce'])
        && !empty($_SESSION['formNonce'])
        && !empty($_SESSION['formTime'])
        && $_POST['nonce'] == $_SESSION['formNonce']
        && time() - $_SESSION['formTime'] >= 2
       ) {
      return true;

    } 
    else {
      return false;
    }

  }

}
