<h1>Stats Settings</h1>

<p>Note: If delivering ABR be sure these options are <a href="<?= BASE_URL ?>/cc-admin/settings_video.php">in line</a> with those used for encoding other formats. For example, keyframe intervals and timing should be the same, otherwise you will encounter 404's on your chunks.</p>

<?php if ($message): ?>
<div class="alert <?=$message_type?>"><?=$message?></div>
<?php endif; ?>

<form method="post">

    <div class="form-group <?=(isset ($errors['encodeto_720p_options'])) ? 'has-error' : ''?>">
      <label for="720p">ffmpeg options for 720p Encoding: </label>
      <textarea class="form-control" id="720p" name="encodeto_720p_options" style="width: 90%;" rows="6"><?= $data['encodeto_720p_options']; ?></textarea>
    </div>

    <div class="form-group <?=(isset ($errors['encodeto_1080p_options'])) ? 'has-error' : ''?>">
      <label for="1080p">ffmpeg options for 1080p Encoding: </label>
      <textarea class="form-control" id="1080p" name="encodeto_1080p_options" style="width: 90%;" rows="6"><?= $data['encodeto_1080p_options']; ?></textarea>
    </div>

    <input type="hidden" value="yes" name="submitted" />
    <input type="hidden" name="nonce" value="<?=$formNonce?>" />
    <input type="submit" class="button" value="Update Settings" />

</form>
