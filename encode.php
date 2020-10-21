<?php

// Startup application
include_once(dirname(__FILE__) . '/config.php');
include_once($bootstrap_path);

// Validate CLI parameters passed to script
$arguments = getopt('', array('video:', 'import::'));
if (!$arguments || !preg_match('/^[0-9]+$/', $arguments['video'])) exit();

// Validate provided import job
if (!empty($arguments['import'])) {
    if (file_exists(UPLOAD_PATH . '/temp/import-' . $arguments['import'])) {
        $importJobId = $arguments['import'];
    } else {
        exit('An invalid import job was passed to the video encoder.');
    }
} else {
    $importJobId = null;
}

// Establish page variables, objects, arrays, etc
$video_id = $arguments['video'];
$ffmpegPath = Settings::get('ffmpeg');
$qtFaststartPath = Settings::get('qtfaststart');
$videoMapper = new VideoMapper();
$videoService = new \VideoService();

$type = "H.264 720p";

// Update any failed videos that are still marked processing
//$videoService->updateFailedVideos();

// Set MySQL wait_timeout to 10 hours to prevent 'MySQL server has gone away' errors
$db->query("SET @@session.wait_timeout=36000");

// Debug Log
if ($config->debugConversion) {
    App::log(CONVERSION_LOG, "\n\n// $type Converter Called...");
    App::log(CONVERSION_LOG, "Values passed to  $type converter:\n" . print_r ($arguments, TRUE));
}

try {
  checkTranscoderPermissions($label, $path);
} catch (Exception $e) {
  handleException($e, false, $importJobId);
}

try {
  //$video = $videoMapper->getVideoByCustom(array('video_id' => $video_id, 'status' => VideoMapper::PENDING_CONVERSION));
  $video = $videoMapper->getVideoByCustom(array('video_id' => $video_id));
  $HDPaths = getHDPaths($video);
  $video = validateVideo($video, $type);
  encode($video, $type);
  cleanup($HDPaths, $video, $type);
} catch (Exception $e) {
  handleException($e, $video, $importJobId);
}

/**
 * Encode to HD720p 
 * 
 * @param Video $video Video object
 * @param string $type type of file, i.e. temp, final, HD, mp3, etc. 
 */
function encode($video, $type)
{
  $config = Registry::get('config');

  $encoderPaths = getEncoderPaths($video);
  $HDPaths = getHDPaths($video, $encoderPaths);
  extract($HDPaths);

  // Build the encoder command.
  $config->debugConversion ? App::log(CONVERSION_LOG, "\nPreparing for $type Encoding...") : null;
  $command = "$ffmpegPath -i $rawVideo " . Settings::get('HD720_encoding_options') . " $tempFilePath >> $debugLogPath 2>&1";

  debugLog("$type Encoding", $debugLogPath, $command);

  // Execute encoding command
  exec($command);

  validateFileCreation($tempFilePath, $video, "temp $type");
  shiftMoovAtom($encoderPaths, $video, $type);
}

/**
 * Set HD720 encoder paths from configs and filenames.
 * 
 * @param array $encoderPaths keyed array of path variables
 * @param Video $video Video object
 */
function getHDPaths($video, $encoderPaths = [], $HdDir = "/HD720/")
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
function validateVideo($video, $type)
{
  $config = Registry::get('config');

  // Validate requested video
  $config->debugConversion ? App::log(CONVERSION_LOG, "Validating requested $type video...") : null;
  if (!$video) throw new Exception("An invalid video was passed to the $type video encoder.");

  // Retrieve video path information
  $config->debugConversion ? App::log(CONVERSION_LOG, 'Establishing variables...') : null;
  // $video->status = VideoMapper::PROCESSING;
  // $video->jobId = posix_getpid();
  $HDPaths = getHDPaths($video);
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
function shiftMoovAtom($encoderVars, $video, $type)
{

  $encoderPaths = getHDPaths($video, $encoderVars);
  extract($encoderPaths);

  // Debug Log
  $config = Registry::get('config');
  $config->debugConversion ? App::log(CONVERSION_LOG, "\nShifting moov atom on $type video...") : null;

  // Prepare shift moov atom command
  $shiftMoovAtomCommand = "$qt_faststart_path $tempFilePath $filePath >> $debugLogPath 2>&1";

  debugLog("$type Shift Moov Atom", $debugLogPath, $shiftMoovAtomCommand);

  // Execute shift moov atom command
  exec($shiftMoovAtomCommand);

  validateFileCreation($filePath, $video, "final $type");
}

/**
 * Check to see if the file was created and output logs if not.
 * 
 * @param string $path path to file
 * @param Video $video Video object
 * @param string $type type of file, i.e. temp, final, HD, mp3, etc. 
 * TODO: log out exception
 */
function validateFileCreation($path, $video, $type)
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
function getEncoderPaths($video)
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
function checkTranscoderPermissions($label, $path)
{
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
function cleanup($encoderPaths, $video, $type)
{
  $config = Registry::get('config');
  $HDPaths = getHDPaths($video, $encoderPaths);
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
function handleException($exception, $video = false, $importJobId = 0)
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
function debugLog($logType, $logPath, $command)
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

