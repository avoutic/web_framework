<form method="post" action="/forgot_password">
  <fieldset>
    <input type="hidden" name="do" value="yes"/>
    <input type="hidden" name="token" value="<?=$this->get_csrf_token()?>"/>

    <legend>Forgot password</legend>
    <p>
      <label for="username">Username</label>
      <input type="text" id="username" name="username"/>
    </p>
    <div>
      <label>&nbsp;</label>
      <input type="submit" value="Reset password" />
    </div>
  </fieldset>
</form>
