<?php
/** Ticket'Hack API
* enables to access to projects, tickets and users with web commands
* 
* root = "ticket'hack.com/api/";
* USER:
* - /user/ access to users data
*   /user/{id} get the json output of the database for the user at the id {id}
*   /user/{id}/projects get the projects the user has access to.
*   /user/me  shortcut to /user/{my id} for authentified users.
*   /user/new adds a user : it neeed POST parameters
*      -name
*      -email
*      -password
*
* PROJECT
* - /project/ access to the projects data
*   /project/{id} get the data of the project if the user has the right to access it.
*   /project/{id}/delete delete the project; only an admin or a maximum level user on this project can use this.
*   /project/{id}/adduser add a user to the project; POST parameters
*      -id_user
*      -access_level
*      please note this function can only be used by someone with higher access level than 0 AND he cannot gives higher clearance to someone else.
*   /project/{id}/removeuser remove a user; POST parameters
*      -id_user
*
*
*   /project/{id}/addticket add a ticket to the project. POST parameters : 
*      -title
*      -priority 
*      -description 
*      -due_date
*
*      returns the ticket as it is in the database. user need to be identificated
*
*   /project/{id}/tickets return tickets of the project
*   /project/{id}/ticket/{id_simple_ticket} return the ticket
*   /project/add add a project; POST parameters
*      -name
*      -ticket_prefix
*      
*      returns th project as it is in the db
* 
* TICKETS
*   /ticket/{id} return the ticket information IF the user has access to the project
*                equivalent to /project/{id}/ticket/{id_simple_ticket}. all the following can be used on both path
*   /ticket/{id}/comments get the comments of the ticket
*   /ticket/{id}/comment/{id_comment} return the comment detail
*   /ticket/{id}/comment/{id_comment}/remove
*   /ticket/{id}/comment/{id_comment}/edit parametre POST
*      -comment
*   /ticket/{id}/addcomment POST
*      -comment
*      user needs to be authenticated
*
* COMMENTS
* /comment/{id}
* /comment/{id}/remove
* /comment/{id}/edit
*    -comment
* 
**/
require_once "router.php";
require_once "../functions.php";

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-type: application/json');

function get($str, $optionnal = false){
    if (isset($_GET[$str])){
        return $_GET[$str];
    }

    if (! $optionnal){
        http_error(400, "missing GET parameter");
    }

    return null;
}

function post($str, $optionnal = false){
    if (isset($_POST[$str])){
        return $_POST[$str];
    }
    if (! $optionnal){
        http_error(400, "missing POST parameter");
    }

    return null;
}

/**
* generate an error and close the programm
* @param code the http code
* @param msg the message you want to display
* @param args additionnal output you may want to display
*
* call a exit at the end of the function.
*/
function http_error($code, $msg, $args = array()){
    http_response_code($code);
    $output = array(
        "status"=>$code,
        "result"=>"error",
        "error"=>$msg
    );

    foreach ($args as $t=>$v){
        $output["$t"] = $v;
    }

    echo json_encode($output);
    exit;
}

/**
* generate a json report and close the programm
* @param content an array
* @param args additionnal output you may want to display
*
* call a exit at the end of the function.
*/
function http_success($content, $args = array()){
    http_response_code(200);
    $output = array(
        "status"=>200,
        "result"=>"ok",
        "content"=>$content
    );

    foreach ($args as $t=>$v){
        $output["$t"] = $v;
    }

    echo json_encode($output);
    exit;
}


/**
* return the user id if he is connected
* kill the process otherwise
**/
function force_auth(){
    if (isset($_SESSION["user_id"])){
        return (int) $_SESSION["user_id"];
    }else{
        http_error(401, "You need to be authentified to access this method");
    }
}

$route = new Route();

/*-----------------------------------------------------------------------------------------------------------------------------------------*/

/** GENERAL
* /login login a user - return truthy or falsey depending on if the user successfully connected
*     - POST:email
*     - POST:password
* /disconnect disconnect a user - clear all cookies and delete the session
*
**/
$route->post(array("/api/login",
                   "/api/user/login",
                   "/api/user/connect"), function(){
    $mail = post("email");
    $password = post("password");

    $validation = validate_user_with_fail($mail, $password);

    if (gettype($validation)=="boolean" && $validation){
        $_SESSION["user_id"] = get_user_by_email($mail)["id"];
        $_SESSION["user"] = get_user_by_email($mail);

        update_last_connection_user($_SESSION["user_id"]);

        $output = array("user_id"=>$_SESSION["user_id"]);
        http_success($output);
    }else{
        if (gettype($validation)=="array"){
            http_error(403, "login denied : ".$validation[1].". try again in 5 minutes.");
        }
        http_error(401, "login failed");
    }
});

$route->route(array("/api/logout",
                    "/api/disconnect",
                    "/api/user/logout",
                    "/api/user/disconnect"), function(){
    if (session_status() == PHP_SESSION_ACTIVE) { session_destroy(); }
    http_success(array("disconnected"=>true));
});



/**
* USER:
* - /user/ access to users data
*   /user/{id} get the json output of the database for the user at the id {id}
*   /user/{id}/projects get the projects the user has access to.
*   /user/me  shortcut to /user/{my id} for authentified users.
*   /user/add adds a user : it neeed POST parameters
*      -name
*      -email
*      -password
**/
$route->post(array("/api/user/new",
                   "/api/user/add"), function(){
    $name = post("name");
    $email = post("email");
    $password = post("password");

    if (user_test_mail($email)){
        http_error(405,"email already taken");
    }

    $id_user = add_user($name, $email, $password);

    $output = array("user_id"=>$id_user);
    http_success($output);
});

$route->route("/api/user/me", function(){    
    $id = force_auth();

    $output = get_user($id);
    http_success($output);
});

$route->get("/api/user/{id}", function($id){
    $id = (int) $id;
    $output = get_user($id);
    http_success($output);
});

/** edit the user info - POST
* POST: user_id
* optional parameter : password, name, email
*
* @return the user infos
*
* you can only edit your own profile
**/
$route->post(array("/api/user/me/edit",
                   "/api/user/{id}/edit"), function($id = null){
    $id = ($id !== null) ? (int) $id : force_auth();
    $name = post("name", true);
    $email = post("email", true);
    $password = post("password", true);

    if ($id != force_auth()){
        http_error(403, "You cannot edit another user's profile");
    }

    $args = array(":id"=>$id);
    $set = array();

    if ($name){
        $args[":name"] = $name;
        $set[]="name = :name";
    }

    if ($email){
        $args[":email"] = $email;
        $set[]="email = :email";
    }

    if ($password){
        $args[":password"] = hash_passwd($password);
        $set[]="password = :password";
    }

    if (count($set) >= 1){
        $req = "UPDATE users SET ".join(",",$set)." WHERE id=:id";
        execute($req, $args);
    }


    $output = get_user($id);
    http_success($output);
});

/**
* Delete the user account
* only the logged in user can delete his own account.
*
**/
$route->delete(array("/api/user/me/delete",
                     "/api/user/{id}/delete"),
               function($id = null){
                   $id = ($id !== null) ? (int) $id : force_auth();

                   if ($id == force_auth()){
                       delete_user($id);
                       session_destroy();

                       $output = array("delete"=>true);
                       http_success($output);
                   }else{
                       http_error(403, "you cannot destroy an account if it is not yours / you are not connected.");
                   }
               });

/**
* get the user projects
* optional parameters : number = 20, offset = 0 by default.
**/
/*todo : test that*/
$route->get(array("/api/user/me/projects",
                  "/api/user/{id}/projects",
                  "/api/projects/list",
                  "/api/project/list"), function($id = null){

    $id = ($id === null) ? force_auth() : (int) $id;
    $offset = get("offset",true) || 0;
    $number = get("number",true) || 20;

    $list = get_projects_for_user($id, $offset, $number);
    $output = array("total" => count($list),
                    "list"=>$list);
    http_success($output);
});

/**
* PROJECT:
* - /project/ access to the projects data
*   /project/{id} get the data of the project if the user has the right to access it.
*   /project/{id}/delete delete the project; only an admin or a maximum level user on this project can use this.
*   /project/{id}/adduser add a user to the project; POST parameters
*      -id_user
*      -access_level
*      please note this function can only be used by someone with higher access level than 0 AND he cannot gives higher clearance to someone else.
*   /project/{id}/removeuser remove a user; POST parameters
*      -id_user
*
*
*   /project/{id}/addticket add a ticket to the project. POST parameters : 
*      -title
*      -priority 
*      -description 
*      -due_date
*
*      returns the ticket as it is in the database. user need to be identificated
*
*   /project/{id}/tickets return tickets of the project
*   /project/{id}/ticket/{id_simple_ticket} return the ticket
*   /project/new add a project; POST parameters
*      -name
*      -ticket_prefix
*      
*      returns th project as it is in the db
**/

$route->post("/api/project/new", function(){
    $name = post("name");
    $ticket_prefix = post("ticket_prefix");
    $id_user = force_auth();

    $id_project = add_project($name ,$id_user, $ticket_prefix);

    $output = array("project_id"=>$id_project);
    http_success($output);
});

/**
* return the project info
* add a user_access info depending on the session; this field will valu false if the user isn't connected
**/
$route->get("/api/project/{id}", function($id){
    $id = (int) $id;
    $project = get_project($id);

    if ($project){

        if (isset($_SESSION["user_id"])){
            $project["user_access"] = access_level($_SESSION["user_id"], $id);
        }else{
            $project["user_access"] = false;
        }

        http_success($project);
    }else{
        http_error(404, "project not found");
    }
});

/*only the creator can change these parameters for now*/
$route->post("/api/project/{id}/edit", function($id){
    $name = post("name", true);
    $id_user = force_auth();

    $args = array(":id"=>$id,
                  ":creator_id"=>$id_user);
    $set = array();

    if ($name){
        $args[":name"] = $name;
        $set[]="name = :name";
    }

    if (access_level($id_user, $id) < 4){
        http_error(403, "You need to be an admin of this project to edit it");
    }

    if (count($set) >= 1){
        $req = "UPDATE projects SET ".join(",",$set)." WHERE id=:id AND creator_id=:creator_id;";
        execute($req, $args);
    }

    $output = get_project($id);
    http_success($output);
});

$route->delete("/api/project/{id}/delete", function($id){
    $id = (int) $id;
    $id_user = force_auth();

    if (is_admin($id_user,$id)){
        delete_project($id);
        $output = array("delete"=>true);
        http_success($output);
    }else{
        http_error(403, "You need to be the admin of this project to be able to delete it", array("delete"=>"fail"));
    }
});

$route->post("/api/project/{id}/adduser", function($id_project){
    $id_project = (int) $id_project; 

    $id_user = (int) post("user_id");
    $access_level = (int) post("access_level");

    $id_current_user = force_auth();
    if (! is_admin($id_current_user, $id_project)){
        http_error(403, "Only an admin can add users to a project.");
    }

    if (access_level($id_current_user, $id_project)< $access_level){
        http_error(403, "you cannot give higher access to someone else.");
    }

    add_link_user_project($id_user, $id_project, $access_level); 

    http_success(array("link_user_project"=>get_link_user_project($id_user, $id_project)));
});

/*return the users on the project*/
$route->get("/api/project/{id}/users", function($id_project){
    $id_project = (int) $id_project;
    $id_user = force_auth();

    if (access_level($id_user, $id_project) > 0){
        http_success(get_users_for_project($id));
    }else{
        http_error(403, "You cannot see the users of a project you are not a part of.");
    }
});

/**
* remove an user from a project
* note that only an admin can remove other users, and only the creator can remove admins.
**/
$route->post("/api/project/{id}/removeuser", function($id_project){
    $id_project = (int) $id_project;
    $id_user = (int) post("user_id");
    $actual_user = force_auth();

    if ($actual_user == $id_user || (is_admin($actual_user, $id_project) && access_level($actual_user, $id_project) > access_level($id_user, $id_project))){
        delete_link_user_project($id_user, $id_project);
        http_success(array("delete"=>true));
    }else{
        http_error(403, "only an admin or the actual user can do that.");
    }
});

$route->post("/api/project/{id}/addticket", function($id_project){
    $id_project = (int) $id_project;
    $title = post("name");
    $priority = post("priority");
    $description = post("description");
    $due_date = post("due_date");
    $manager_id = post("manager_id", true) || null;

    $creator_id = force_auth();

    /*verify the user has the right to create tickets*/
    if (access_level($creator_id, $id_project) >= 3){
        $id = add_ticket($title, $id_project, $creator_id, $manager_id, $priority, $description, $due_date);
        $output = array("id_ticket" => $id);
        http_success($output); 
    }else{
        http_error(403, "You do not have the permission to create a ticket on this project");
    }

});

$route->get("/api/project/{id}/tickets", function($id_project){
    $id_project = (int) $id_project;
    $current_user = force_auth();

    if (get_project(get_project)){ 
        if (access_level($current_user, $id_project) >= 1){
            http_success(get_tickets_for_project($id_project));
        }else{
            http_error(403, "You do not have the right to access this project or his tickets");
        }     
    }else{
        http_error(404, "This project does not exist");
    }
});

$route->get("/api/project/{id_project}/ticket/{id_simple_ticket}", function($id_project, $id_simple_ticket){
    $id_project = (int) $id_project;
    $current_user = force_auth();

    if (get_project(get_project)){ 
        if (access_level($current_user, $id_project) >= 1){
            http_success(get_tickets_for_project($id_project));
            $ticket = get_ticket_simple($id_project, $id_simple_ticket);
            if ($ticket){
                http_success($ticket);
            }else{
                http_error(404, "This ticket does not exists within the project.");
            }
        }else{
            http_error(403, "You do not have the right to access this project or his tickets");
        }     
    }else{
        http_error(404, "This project does not exist");
    }
});


/**
* TICKETS
*   /ticket/{id} return the ticket information IF the user has access to the project
                 equivalent to /project/{id}/ticket/{id_simple_ticket}. all the following can be used on both path
*   /ticket/{id}/comments get the comments of the ticket
*      -comment
*   /ticket/{id}/addcomment POST
*      -comment
*      user needs to be authenticated
**/

/**
* return the list of tickets of the connected user
* optional parameters : number = 20, offset = 0 by default.
**/
$route->get(array("/api/ticket/list",
                  "/api/tickets/list"), function(){
    $id_user = force_auth();
    $offset = get("offset",true) || 0;
    $number = get("number",true) || 20;
    $tickets = get_tickets_for_user($id_user, $offset, $number);

    http_success($tickets);
});


$route->get("/api/ticket/{id}", function($id){
    $access_level = rights_user_ticket(force_auth(), $id);
    if ($access_level >=1){
        http_success(get_ticket($id));
    }else{
        http_error(403, "You do not have the right to se this ticket");
    }
});

$route->post("/api/ticket/{id}/edit", function($id_ticket){
    $id_ticket = (int) $id_ticket;
    $ticket = get_ticket($id_ticket);

    $params = array(
        "name"=>post("name", true),
        "priority"=>post("priority", true),
        "description"=>post("description", true),
        "due_date"=>post("due_date", true),
        "manager_id"=>post("manager_id", true)
    );

    $access_level = rights_user_ticket(force_auth(), $id_ticket);

    /*verify the user has the right to edit the ticket*/
    /*creator can modify / manager can modify without modifying himself*/
    if ($access_level >= 4 || ($access_level == 3 && !isset($params["manager_id"]))){

        $args = array(":ticket_id"=>$id_ticket);
        $set = array();

        foreach($params as $t=>$v){
            if ($v){
                $args[":$t"] = $v;
                $set[] = "$t = :$t";
            }
        }

        if (count($set) >= 1){
            $req = "UPDATE tickets SET ".join(",",$set)." WHERE id=:ticket_id;";
            execute($req, $args);
        }

        http_success(get_ticket($id_ticket));
    }else{
        http_error(403, "You do not have the permission to modify this ressource");
    }

    ;
});

$route->delete("/api/ticket/{id}/delete", function($id_ticket){
    if (rights_user_ticket(force_auth(), $id_ticket) >= 4){
        delete_ticket($id_ticket);
        http_success(array("delete"=>true));
    }else{
        http_error(403,"You do not have the right to do that");
    }
});

$route->post("/api/ticket/{id}/addcomment", function($ticket_id){
    $ticket_id = (int) $ticket_id;
    $comment = post("comment");
    $creator_id = force_auth();
    if (rights_user_ticket($creator_id, $ticket_id) >= 2){
        $id = add_comment($ticket_id, $creator_id, $comment);
        $output = array("id_comment"=>$id);
        http_success($output);
    }else{
        http_error(403, "You do not have the permission to comment");
    }
});

/*test 403*/
$route->get("/api/ticket/{id}/comments", function($id){
    $user_id = force_auth();
    $ticket_id = (int) $id;

    if (rights_user_ticket($creator_id, $ticket_id) >= 2){
        http_success(get_comments_for_ticket($id));
    }else{
        http_error(403, "you do not have the permission to access this project");
    }
});


/**
* COMMENTS
* GET /api/comment/{id}
* POST /api/comment/{id}/edit
*    -comment
* DELETE /api/comment/{id}/delete
*
**/
$route->get("/api/comment/{id_comment}", function($id_comment){
    if (rights_user_comment(force_auth(), $id_comment) > 0){
        http_success(get_comment($id_comment));
    }else{
        http_error(403, "You do not have the permission to access this comment");
    }
});

$route->post("/api/comment/{id}/edit", function($comment_id){
    $comment = post("comment");
    $user = force_auth();

    if (rights_user_comment($user, $comment_id) !== 2){
        http_error(403,"You cannot modify this comment");
    }else{
        $id = edit_comment($comment_id, $comment);
        http_success(get_comment($comment_id));
    }
});

$route->delete("/api/comment/{id}/delete", function($comment_id){
    $comment_id = (int) $comment_id;
    $id_user = force_auth();

    $id_project = execute("SELECT project_id FROM tickets WHERE id IN (SELECT ticket_id FROM comments WHERE id = ?)", array($comment_id))->fetch()["project_id"];

    if (rights_user_comment($id_user, $comment_id) === 2){
        delete_comment($comment_id);
        http_success(array("delete"=>true));
    }else{
        http_error(403, "You cannot delete this comment as it is not yours / you are not admin");
    }    
});

/**
* Error Handling
* put it in the end for clarity, it works in all the cases.
**/
$route->error_404(function(){
    http_error(404, "Error 404 - The server cannot find a page corresponding to your request. please check your url and method. do note this does NOT correspond to missing parameters.");
});
?>