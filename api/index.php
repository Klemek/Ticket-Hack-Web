<?php
/** Ticket'Hack API
* enables to access to projects, tickets and users with web commands
* to have a detailed view of the API, please go to https://app.swaggerhub.com/apis/top8/TicketHack/1.0.0
* json detailed scheme of the API can be found in ./scheme_by_swagger.zip
**/

require_once "router.php";
require_once "../functions.php";

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-type: application/json');

/**
* shorcut to $_GET[""] with error handling
* @param $str the item to fetch
* @param optionnal if the argument can be missing. setting it to false raise an error if it is missing and stop the program.
*
* @return $_GET[$str]
**/
function get($str, $optionnal = false){
    if (isset($_GET[$str])){
        return $_GET[$str];
    }

    if (! $optionnal){
        http_error(400, "missing GET parameter");
    }

    return null;
}

/**
* shorcut to $_POST[""] with error handling
* @param $str the item to fetch
* @param optionnal if the argument can be missing. setting it to false raise an error if it is missing and stop the program.
*
* @return $_POST[$str]
**/
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
* generate an http error and close the programm
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
* check if the user is connected and close the program if he is not.
* @return the user id if he is connected
**/
function force_auth(){
    if (isset($_SESSION["user_id"])){
        return (int) $_SESSION["user_id"];
    }else{
        http_error(401, "You need to be authentified to access this method");
    }
}

/* anti ddos handmade */
if (verify_user_ddos() == false){
    http_error(403, "Too many requests in less than a minute. please wait a little");
}

// our route
$route = new Route();

/**
* ------------------------------------------------------- GENERAL --------------------------------------------------------------
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

        http_error(401, "login failed", $output);
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
* ------------------------------------------------------- USER --------------------------------------------------------------
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

$route->get("/api/user/bymail", function(){
    $mail = get("mail");
    $user = get_user_by_email($mail);
    force_auth();
    if ($user){ 
        http_success($user);
    }else{
        http_error(404, "No user possess this mail");
    }
});

$route->get("/api/user/{id}", function($id){
    $id = (int) $id;
    $output = get_user($id);
    if ($output){
        http_success($output);
    }else{
        http_error(404, "User Not Found");
    }
});

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

$route->get(array("/api/user/me/projects",
                  "/api/user/{id}/projects",
                  "/api/projects/list",
                  "/api/project/list"), function($id = null){

    $id = ($id === null) ? force_auth() : (int) $id;
    $offset = (int) (get("offset",true) ?? 0);
    $number = (int) (get("number",true) ?? 20);

    $list = get_projects_for_user($id, $number, $offset);
    $output = array("total" => get_number_projects_for_user($id),
                    "list"=>$list,
                    "offset"=>$offset,
                    "number"=>$number);
    http_success($output);
});

/**
* ------------------------------------------------------- ¨PROJECT --------------------------------------------------------------
**/

$route->post("/api/project/new", function(){
    $name = post("name");
    $ticket_prefix = post("ticket_prefix");
    $id_user = force_auth();

    $id_project = add_project($name ,$id_user, $ticket_prefix);

    $output = array("project_id"=>$id_project);
    http_success($output);
});

$route->get("/api/project/{id}", function($id){
    $id = (int) $id;
    $project = get_project($id);
    $id_user = force_auth();

    if ($project){
        $access = access_level($id_user, $id);
        if ($access==0){
            http_error(403,"You do not have the permission to access this project");
        }

        $project["user_access"] = $access;

        http_success($project);
    }else{
        http_error(404, "project not found");
    }
});

$route->post("/api/project/{id}/edit", function($id){
    $name = post("name", true);
    $id_user = force_auth();

    $args = array(":id"=>$id,
                  ":creator_id"=>$id_user);
    $set = array();

    if ($name){
        $args[":name"] = $name;
        $set[]="name = :name";

        $args[":editor_id"] = $id_user;
        $set[]="editor_id = :editor_id";

        $set[]="edition_date = NOW()";
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

    if (get_link_user_project($id_user, $id_project) !== false){
        http_error(400, "link already exists - use /api/project/{id}/edituser to edit a user");
    }

    add_link_user_project($id_user, $id_project, $access_level); 

    http_success(array("link_user_project"=>get_link_user_project($id_user, $id_project)));
});

$route->post("/api/project/{id}/edituser", function($id_project){
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

    if (get_link_user_project($id_user, $id_project) !== false){
        edit_link_user_project($id_user, $id_project, $access_level);
        http_success(array("link_user_project"=>get_link_user_project($id_user, $id_project)));
    }else{
        http_error(403, "link doesn't exist");
    }
});

$route->get("/api/project/{id}/users", function($id_project){
    $id_project = (int) $id_project;
    $id_user = force_auth();

    $offset = (int) (get("offset",true) ?? 0);
    $number = (int) (get("number",true) ?? 20);

    if (! project_exists($id_project)){
        http_error(404, "Project Not Found");
    }

    if (access_level($id_user, $id_project) > 0){
        $output = array(
            "list"=>get_users_for_project($id_project, $number, $offset),
            "total"=>get_number_users_for_project($id_project)
        );
        http_success($output);
    }else{
        http_error(403, "You cannot see the users of a project you are not a part of.");
    }
});

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
    $state= post("state");
    $type = post("type");
    $due_date = post("due_date", true);
    $manager_id = post("manager_id", true);

    $creator_id = force_auth();

    /*verify the user has the right to create tickets*/
    if (access_level($creator_id, $id_project) >= 3){
        $id = add_ticket($title, $id_project, $creator_id, $manager_id, $priority, $description, $due_date, $state, $type);
        $output = get_ticket($id);
        $output["id_ticket"] = $id;
        http_success($output); 
    }else{
        http_error(403, "You do not have the permission to create a ticket on this project");
    }

});

$route->get("/api/project/{id}/tickets", function($id_project){
    $id_project = (int) $id_project;
    $current_user = force_auth();

    $offset = (int) (get("offset",true) ?? 0);
    $number = (int) (get("number",true) ?? 20);

    if (project_exists($id_project)){ 
        if (access_level($current_user, $id_project) >= 1){
            $output= array(
                "list"=> get_tickets_for_project($id_project, $number, $offset),
                "total"=>get_number_tickets_for_project($id_project)
            );
            http_success($output);
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

    if (project_exists($id_project)){ 
        if (access_level($current_user, $id_project) >= 1){
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
* ------------------------------------------------------- TICKETS --------------------------------------------------------------
**/

$route->get(array("/api/ticket/list",
                  "/api/tickets/list"), function(){
    $id_user = force_auth();
    $offset = (int) (get("offset",true) ?? 0);
    $number = (int) (get("number",true) ?? 150000000);
    $tickets = get_tickets_for_user($id_user, $number, $offset);

    $output = array(
        "list"=>$tickets,
        "total"=>get_number_tickets_for_user($id_user)
    );
    http_success($output);
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
        "manager_id"=>post("manager_id", true),
        "state"=>post("state",true),
        "type"=>post("type",true)
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
            $set[] = "editor_id = :editor_id";
            $args[":editor_id"] = force_auth();


            $req = "UPDATE tickets SET ".join(",",$set).", edition_date=NOW() WHERE id=:ticket_id;";
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
        $output = array("id_comment"=>$id,
                        "comment"=>get_comment($id));
        http_success($output);
    }else{
        http_error(403, "You do not have the permission to comment");
    }
});

$route->get("/api/ticket/{id}/comments", function($id){
    $user_id = force_auth();
    $ticket_id = (int) $id;

    $offset = (int) (get("offset",true) ?? 0);
    $number = (int) (get("number",true) ?? 20);

    if (rights_user_ticket($user_id, $ticket_id) >= 1){
        $output = array(
            "list"=>get_comments_for_ticket($id, $number, $offset),
            "total"=>get_number_comments_ticket($id)
        );

        http_success($output);
    }else{
        http_error(403, "you do not have the permission to access this project");
    }
});

/**
* ------------------------------------------------------- COMMENTS --------------------------------------------------------------
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

    if (rights_user_comment($user, (int) $comment_id) !== 2){
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
* ------------------------------------------------------- ERROR HANDLING --------------------------------------------------------------
**/
$route->error_404(function(){
    http_error(404, "Error 404 - The server cannot find a page corresponding to your request. please check your url and method. do note this does NOT correspond to missing parameters.");
});

//php file : do not put "? >" at the end to the risk of having a whitespace included 