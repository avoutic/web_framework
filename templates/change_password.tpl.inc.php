<form method="post" action="/change_password">
  <fieldset>
     <input type="hidden" name="do" value="yes"/>
     <input type="hidden" name="token" value="<?= $this->get_csrf_token(); ?>"/>

     <legend>Change password</legend>
     <p>
       <label for="orig_password">Original password</label>
       <input type="password" id="orig_password" name="orig_password"/>
     </p>
     <p>
       <label for="password">Password</label>
       <input type="password" id="password" name="password"/>
     </p>
     <p>
       <label for="password2">Password verification</label>
        <input type="password" id="password2" name="password2"/>
     </p>
   </fieldset>
   <p>
     <label>&nbsp;</label> <input type="submit" value="Change" />
   </p>
</form>
