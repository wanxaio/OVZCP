{% extends "layouts/nonsecure-base.volt" %}

{% block main %}

<div class="row">
    <div class="col-xs-12 col-sm-12 col-md-5 col-lg-4" style="margin: 0 auto; float: none;">
        <div class="panel panel-default">
            <div class="panel-heading">
                Forgot Your Password?
            </div>
            <form method="post" action="/forgot-password" id="forgot-form">
            <div class="panel-body">
                <fieldset>

                    {# Flash Message #}
                    <?php $this->flashSession->output(); ?>

                    <div class="form-group input-group">
                        <span class="input-group-addon"><i class="fa fa-user"></i></span>
                        <input class="form-control" placeholder="Loginname" type="text" name="loginname">
                    </div>                    
                </fieldset>
            </div>
            <div class="panel-footer">
                <a href="/login" class="btn btn-default">Cancel</a>
                <button type="submit" class="btn btn-primary">Send Instructions</button>
            </div>
            </form>
        </div>
    </div>
</div>
{% endblock %}