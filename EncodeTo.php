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
    Settings::set('HD720_encoding_options', '-acodec aac -b:a 128k -ac 2 -ar 44100  -af "aresample=first_pts=0" -pix_fmt yuv420p -vsync -1 -sn -vcodec libx264 -r 30 -vf "scale=min(1280\,trunc(iw/2)*2):trunc(ow/a/2)*2" -threads 0 -maxrate 3000k -bufsize 3000k -preset slower -profile:v high -tune film  -x264opts keyint=60:min-keyint=60:no-scenecut -map_metadata -1 -f mp4 -y');

    Filesystem::createDir(UPLOAD_PATH . '/HD720/');
  }
  /**
   * Attaches plugin methods to hooks in code base
   */
  public function load()
  {
    // Starting at top of upload completion controller, b/c we still have a videoId in the session vars
    Plugin::attachEvent('encoder.after.thumbnail', array(__CLASS__, 'hd_encode'));
    Plugin::attachEvent('encoder.after.thumbnail', array(__CLASS__, 'createSMIL'));
  }

  /**
   * Encode an HD720p version of the video.
   * TODO: validate user, etc
   */
  public static function hd_encode($video)
  {
    $type = "H.264 720p";

    if (class_exists('Wowza')) {
      Filesystem::createDir(UPLOAD_PATH . '/HD720/');
    }

    $config = Registry::get('config');
    $config->debugConversion ? App::log(CONVERSION_LOG, "\n$type Converter Called For Video: $video->videoId") : null;

    if (!EncodeTo::isAudio($video)) {
      $HDPaths = EncodeTo::getHDPaths($video);
      EncodeTo::encode($video, $type);
      EncodeTo::cleanup($HDPaths, $video, $type);
    }
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
      //$video = $videoMapper->getVideoByCustom(array('video_id' => $video_id, 'status' => VideoMapper::PENDING_CONVERSION));
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
  public static function encode($video, $type)
  {
    $config = Registry::get('config');

    $encoderPaths = EncodeTo::getEncoderPaths($video);
    $HDPaths = EncodeTo::getHDPaths($video, $encoderPaths);
    extract($HDPaths);

    // Build the encoder command.
    $config->debugConversion ? App::log(CONVERSION_LOG, "\nPreparing for $type Encoding...") : null;
    $command = "$ffmpegPath -i $rawVideo " . Settings::get('HD720_encoding_options') . " $tempFilePath >> $debugLogPath 2>&1";

    EncodeTo::debugLog("$type Encoding", $debugLogPath, $command);

    // Execute encoding command
    exec($command);

    EncodeTo::validateFileCreation($tempFilePath, $video, "temp $type");
    EncodeTo::shiftMoovAtom($encoderPaths, $video, $type);
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
   * Set HD720 encoder paths from configs and filenames.
   * 
   * @param array $encoderPaths keyed array of path variables
   * @param Video $video Video object
   */
  public static function validateVideo($video, $type)
  {
    $config = Registry::get('config');

    // Validate requested video
    $config->debugConversion ? App::log(CONVERSION_LOG, "Validating requested $type video...") : null;
    if (!$video) throw new Exception("An invalid video was passed to the $type video encoder.");

    // Retrieve video path information
    $config->debugConversion ? App::log(CONVERSION_LOG, 'Establishing variables...') : null;
    $HDPaths = EncodeTo::getHDPaths($video);
    extract($HDPaths);

    // Verify Raw Video Exists
    $config->debugConversion ? App::log(CONVERSION_LOG, "Verifying raw $type video exists...") : null;
    if (!file_exists($rawVideo)) throw new Exception("The raw $type video file does not exists. The id of the video is: $video->videoId");

    // Verify Raw Video has valid file size
    // (Greater than min. 5KB, anything smaller is probably corrupted
    $config->debugConversion ? App::log(CONVERSION_LOG, 'Verifying raw video was valid size...') : null;
    if (!filesize($rawVideo) > 1024 * 5) throw new Exception("The raw $type video file is not a valid filesize. The id of the video is: $video->videoId");

    return $video;
  }

  /**
   * Shift Moov atom for faster streaming start times.
   * 
   * @param array $configs array of config variables. 
   * @param Video $video Video object
   * @param string $type type of file, i.e. temp, final, HD, mp3, etc. 
   */
  public static function shiftMoovAtom($encoderVars, $video, $type)
  {

    $encoderPaths = EncodeTo::getHDPaths($video, $encoderVars);
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
    $HDPaths = EncodeTo::getHDPaths($video, $encoderPaths);
    extract($HDPaths);
    try {
      // Delete pre-faststart files
      $config->debugConversion ? App::log(CONVERSION_LOG, 'Deleting temp file for $type video...') : null;
      Filesystem::delete($tempFilePath);

    } catch (Exception $e) {
      App::alert("Error During $type Video Encoding Cleanup for video: $video->videoId", $e->getMessage());
      App::log(CONVERSION_LOG, $e->getMessage());
    }
  }

  /**
   * Handle general encoder failure/exception 
   * 
   */
  public static function handleException($exception, $video = false, $importJobId = 0)
  {
    // Update video status
    if ($video) {
      $video->status = \VideoMapper::FAILED;
      $video->jobId = null;
      $videoMapper->save($video);
    }

    // Notify import script of error
    if ($importJobId) {
      \ImportManager::executeImport($importJobId);
    }

    App::alert('Error During Video Encoding', $exception->getMessage());
    App::log(CONVERSION_LOG, $exception->getMessage());
    exit();
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

}
