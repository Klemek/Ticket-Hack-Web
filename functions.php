<?php

/*start the session*/
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once "connexion_db.php";

/** prepare and execute the query
* $req = request (string)
* $values = array
@return PDOStatement $sth
**/
function execute($req, $values){
    global $db;
    $sth = $db->prepare($req);

    if (! $sth){
        echo "Erreur SQL";
        print_r($db->errorInfo());
        die();
    }

    $sth->execute($values);

    return $sth;
}

/*apply the init SQL script*/
function init_database(){
    global $db;
    $path_to_init = "./sql/initdb.sql";

    $file = file_get_contents($path_to_init);

    $db->exec($file);
}

/*-------------------------------------------------------------- USERS -------------------------------------------------------------------*/
//TODO : edit


/** hash the password
* V1 : stupid hash + permasalt
**/
function hash_passwd($pswd){//TODO add salt with timestamp
    $salt_pre = "ticket'hack";
    $salt_post = "145698235";

    return hash('sha256',$salt_pre.$pswd.$salt_post);
}

/*test a mail to check if it exists within the database
* @return boolean
**/
function user_test_mail($mail){
    $req = "SELECT COUNT(email) FROM users WHERE email=?;";
    $values = array($mail);

    $result = execute($req, $values);

    return $result->fetchColumn() === 1;   
}

/** add a user
* the function user_test_mail is called prior to this function
* return the id of the row inserted
**/
function add_user($name, $email, $password){
    global $db;

    if (user_test_mail($email)){
        die("Error - Mail already exists in the database");
    }

    $req = "INSERT INTO users(name, email, password) VALUES (?,?,?) RETURNING id;";
    $values = array($name, $email, hash_passwd($password));

    $sth = execute($req, $values);
    return $sth->fetch()["id"];
}

/** get the data from the user
* the password will NOT be transmitted here
*
**/
function get_user($id){
    global $db;
    $req = "SELECT * FROM users WHERE id = ". (int) $id;

    $res = $db->query($req)->fetchall(PDO::FETCH_ASSOC);
    if (count($res)){
        $output = $res[0];
        unset($output["password"]);
        return $output;
    }
    return false;
}

function get_user_by_email($email){
    global $db;
    $req = "SELECT * FROM users WHERE email = ?";
    $values = array($email);

    $res = execute($req,$values)->fetchall(PDO::FETCH_ASSOC);
    if (count($res)){
        $output = $res[0];
        unset($output["password"]);
        return $output;
    }
    return false;
}

/** validate the user's password**/
function validate_user($email, $password){
    global $db;

    $req = "SELECT count(email) FROM users WHERE email=? AND password=?";
    $values = array($email, hash_passwd($password));

    $result = execute($req, $values);
    return $result->fetchColumn() === 1; 
}

function update_last_connection_user($id){
    global $db;
    $req = "UPDATE users SET last_connection_date = NOW() WHERE id = ".(int) $id;

    $db->exec($req);
}

function delete_user($id){
    global $db;
    $req = "DELETE FROM users WHERE id = ".(int) $id;
    $db->exec($req);
}
/*------------------------------------------------------------ PROJECTS ------------------------------------------------------------------*/
//TODO : edit, update last time modified

/** add a project
* return the id of the row inserted
**/
function add_project($name, $creator_id, $ticket_prefix){
    $req = "INSERT INTO projects(name, creator_id, ticket_prefix) VALUES (?, ?, ?)  RETURNING id;";
    $values = array($name, $creator_id, $ticket_prefix);

    $sth = execute($req, $values);
    return $sth->fetch()["id"];
}

/*delete a project*/
function delete_project($id){
    $req = "DELETE FROM projects WHERE id = ?";
    $values = array($id);
    execute($req,$values);
}

/*get a project*/
function get_project($id){
    $req = "SELECT * FROM projects WHERE id = ?";
    $values = array($id);

    $sth = execute($req,$values);
    return $sth->fetch(PDO::FETCH_ASSOC);
}

/*------------------------------------------------------------ PROJECTS & USER -----------------------------------------------------------*/

function add_link_user_project($id_user, $id_project, $level){
    //check if the user does'nt already possess a link to this project
    if (get_link_user_project($id_user, $id_project) !== false){
        die("Link already existing");
    }

    $req = "INSERT INTO link_user_project VALUES (?,?,?);";
    $values = array($id_user, $id_project, $level);
    execute($req, $values);
}

/*get the link between the user and the project. returns false if the link doesn't exist*/
function get_link_user_project($id_user, $id_project){
    $req = "SELECT * FROM link_user_project WHERE user_id = ? AND project_id = ?";
    $values = array($id_user, $id_project);

    $sth = execute($req, $values);
    if ($sth->rowCount() > 0){
        return $sth->fetch(PDO::FETCH_ASSOC);
    }
    return false;
}

/*get all the projects for a user*/
function _for_user($id_user){
    $req = "SELECT * FROM projects WHERE id IN (SELECT project_id FROM link_user_project WHERE user_id = ?) OR creator_id = ?;";
    $values = array($id_user, $id_user);

    $sth = execute($req, $values);

    return $sth->fetchall(PDO::FETCH_ASSOC);
}   

/*get all the users for the project*/
function get_users_for_project($id_project){
    $req = "SELECT * FROM users WHERE id IN (SELECT user_id FROM link_user_project WHERE project_id = ? UNION SELECT creator_id FROM projects WHERE id=?);";
    $values = array($id_project,$id_project);

    $sth = execute($req, $values);

    return $sth->fetchall(PDO::FETCH_ASSOC);
}

/*modify the level on the (id_user, id_project) link*/
function edit_link_user_project($id_user, $id_project, $level){
    //check if the user does'nt already possess a link to this project
    if (get_link_user_project($id_user, $id_project) === false){
        die("Lien non existant");
    }

    $req = "UPDATE link_user_project SET user_access = ? WHERE user_id = ? AND project_id = ?;";
    $values = array($level, $id_user, $id_project);
    execute($req, $values);
}

/*delete the link*/
function delete_link_user_project($id_user, $id_project){
    $req = "DELETE FROM link_user_project WHERE user_id = ? AND project_id = ?;";
    $values = array($id_user, $id_project);
    execute($req, $values);
}

//todo : add multiple admin by level, not only creator
function is_admin($id_user, $id_project){
    return access_level($id_user, $id_project) >= 4;
}

/**
* return the access that a user has for a project.
* 0 : no access
* 1 : read only
* 2 : comment + read
* 3 : add ticket + comment + read
* 4 : admin
* 5 : creator
**/
function access_level($id_user, $id_project){
    /*creator*/
    $req = "SELECT creator_id FROM projects WHERE id=?";
    $args = array($id_project);

    $id = (int) execute($req, $args)->fetch()["creator_id"];

    if ($id == $id_user){
        return 5;
    }
    
    /*other ranks*/
    $req = "SELECT COALESCE(user_access,0) FROM link_user_project WHERE project_id = ? AND user_id = ?";
    $values = array($id_project,$id_user);
    $req = execute($req, $values);
    
    $access_level = $req->fetchColumn();
    return (int) $access_level;
}

/*----------------------------------------------------------------- TICKETS --------------------------------------------------------------*/
//TODO : edit ticket, get ticket 

/** add a ticket
* return the id of the row inserted
**/
function add_ticket($title, $project_id, $creator_id, $manager_id ,$priority, $description, $due_date){
    $req = "INSERT INTO tickets(simple_id, name, project_id, creator_id, manager_id, priority, description, due_date) VALUES (:simple_id, :name, :project_id, :creator_id, :manager_id, :priority, :description, :due_date) RETURNING id;";

    $simple_id = count(get_tickets_for_project($project_id));

    $values = array(
        ":simple_id" => $simple_id,
        ":name" => $title,
        ":project_id" => $project_id,
        ":creator_id" => $creator_id,
        ":manager_id" => $manager_id,
        ":priority" => $priority,
        ":description" => $description,
        ":due_date" => $due_date
    );

    $sth = execute($req, $values);
    return $sth->fetch()["id"];
}

/*return the ticket*/
function get_ticket($id){
    global $db;

    $req = "SELECT * FROM tickets WHERE id = ".(int) $id." LIMIT 1;";
    $res = $db->query($req)->fetch(PDO::FETCH_ASSOC);
    if (count($res)){
        return $res;
    }

    return false;
}

function get_ticket_simple($id_project, $id_simple){
    $req = "SELECT * FROM tickets WHERE project_id=? AND simple_id=? ;";
    $values = array($id_project, $id_simple);

    $sth = execute($req, $values);

    return $sth->fetch(PDO::FETCH_ASSOC);
}

/*return all tickets of a project*/
function get_tickets_for_project($id_project){
    global $db;

    $req = "SELECT * FROM tickets WHERE project_id = ".(int) $id_project;

    return $db->query($req)->fetchall(PDO::FETCH_ASSOC);
}

/*delete a ticket from the database (!= ticket passed to achieved) */
function delete_ticket($id){
    global $db;
    $req = "DELETE FROM tickets WHERE id = ".(int) $id;
    $db->exec($req);
}

/** return the rights of the user on the ticket
*0 : no access
*1 : read only
*2 : comment
*3 : manager (edit)
*4 : creator (edit++)
*5 : admin (edit+++)
*
* return false in case of error
**/
function rights_user_ticket($user_id, $ticket_id){
    $ticket = get_ticket($ticket_id);
    if ($ticket === false){
        return false;
    }
    
    if (access_level($user_id, (int) $ticket["project_id"]) == 0){
        return 0;
    }
    
    if (is_admin($user_id, (int) $ticket["project_id"])){
        return 5;
    }
    
    if ((int) $ticket["creator_id"] == $user_id){
        return 4;
    }
    
    if ((int) $ticket["manager_id"] == $user_id){
        return 3;
    }
    
    return access_level($user_id, (int) $ticket["project_id"]);
}

/*----------------------------------------------------------------- COMMENTS -------------------------------------------------------------*/

/*add a comment
@return the id of the created comment*/
function add_comment($ticket_id, $creator_id, $comment){
    $req = "INSERT INTO comments(ticket_id, creator_id, comment) VALUES (?,?,?) RETURNING id;";
    $values = array($ticket_id, $creator_id, $comment);

    $sth = execute($req, $values);
    return $sth->fetch()["id"];
}

function edit_comment($id, $comment){
    $req = "UPDATE comments SET comment = ?, edition_date = NOW() WHERE id = ?";
    $values = array($comment, $id);

    execute($req, $values);
}

function delete_comment($id){
    global $db;
    $req = "DELETE FROM comments WHERE id = ".(int) $id;
    $db->exec($req);
}

function get_comment($id){
    $req = "SELECT * FROM comments WHERE id = ? LIMIT 1;";
    $values= array($id);

    $sth = execute($req, $values);
    return $sth->fetch(PDO::FETCH_ASSOC);
}

function get_comments_for_ticket($id_ticket){
    $req = "SELECT * FROM comments WHERE ticket_id = ?";
    $values= array($id_ticket);

    $sth = execute($req, $values);
    return $sth->fetchall(PDO::FETCH_ASSOC);
}

/** return the rights of the user on the ticket
*0 : no access
*1 : read
*2 : edit
* return false in case of error
**/
function rights_user_comment($user_id, $comment_id){
    $comment = get_comment($comment_id);

    $ticket = get_ticket((int) $comment["ticket_id"]);
    
    if ($ticket === false){
        return false;
    }

    $ticket_id = $comment["ticket_id"];
    
    if (rights_user_ticket($user_id, (int) $ticket_id) == 0){
        return 0;
    }
    
    if ($ticket["creator_id"] == $user_id || is_admin($user_id, (int) $ticket["project_id"])){
        return 2;
    }
    
    return 1;
}

/*----------------------------------------------------------------- CATEGORIES -----------------------------------------------------------*/

/** add a category
* return the inserted id
**/
function add_category($project_id, $name_category){
    $req = "INSERT INTO categories(project_id, name) VALUES (?,?) RETURNING id;";
    $values = array($project_id, $name_category);

    $sth = execute($req, $values);
    return $sth->fetch()["id"];
}

function edit_category($id, $project_id, $name_category){
    $req = "UPDATE categories SET project_id = ?, name = ? WHERE id = ?";
    $values = array($project_id, $name_category, $id);

    $sth = execute($req, $values);
}

function delete_category($id){
    global $db;
    $req = "DELETE FROM categories WHERE id = ".(int) $id;
    $db->exec($req);
}

function get_category($id){
    global $db;
    $req = "SELECT * FROM categories WHERE id = ".(int) $id;
    $sth = $db->query($req);
    return $sth->fetch(PDO::FETCH_ASSOC);
}

function get_categories_for_project($id_project){
    global $db;
    $req = "SELECT * FROM categories WHERE project_id = ".(int) $id_project;
    $sth = $db->query($req);
    return $sth->fetchall(PDO::FETCH_ASSOC);
}

/*--------------------------------------------------------- CATEGORIES & TICKETS ---------------------------------------------------------*/

/** add the link if the ticket and the category are on the same project
* return true if the link exists at the end of this function
**/
function add_link_ticket_category($id_ticket, $id_category){
    $ticket = get_ticket($id_ticket);
    $category = get_category($id_category);

    if ((int) $ticket["project_id"] == (int) $category["project_id"] && (int) $category["project_id"] != 0){        
        $req = "INSERT INTO link_ticket_category VALUES (?,?)";
        $values = array($id_ticket, $id_category);

        execute($req, $values);//postgreSQL check itself for duplicatas
        return true;
    }

    return false;
}

/** delete the link between a ticket and a category**/
function delete_link_ticket_category($id_ticket, $id_category){
    $req = "DELETE FROM link_ticket_category WHERE ticket_id = ? AND category_id = ?;";
    $values = array($id_ticket, $id_category);

    execute($req, $values);
}

/* get categories id for a ticket */
function get_categories_for_ticket($id_ticket){
    $req = "SELECT * FROM categories WHERE id IN (SELECT category_id FROM link_ticket_category WHERE ticket_id = ?);";
    $values = array($id_ticket);

    $sth = execute($req, $values);

    return $sth->fetchall(PDO::FETCH_ASSOC);
}

/*get all tickets of a designed category*/
function get_tickets_for_category($id_category){
    $req = "SELECT * FROM tickets WHERE id IN (SELECT ticket_id FROM link_ticket_category WHERE category_id = ?);";
    $values = array($id_category);

    $sth = execute($req, $values);

    return $sth->fetchall(PDO::FETCH_ASSOC);
}


/*mail*/

function simplemail($to, $subject, $message){
    $headers = 'From: kalioz@kalioz.fr' . "\r\n" .
        'Reply-To: kalioz@kalioz.fr' . "\r\n" .
        'X-Mailer: PHP/' . phpversion();

    echo "test";

    mail($to, $subject, $message, $headers);
}
/* -+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+ TESTS +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-*/

/*test zone*/
$user_0 = 766929234;
$project_0 = 1571240745;
$ticket_0 = 1957972491;
$cat_0 = 1831344299;
$comment_0 = 291600761;

//print_r(get_tickets_for_category(1831344299));
//print_r(get_categories_for_ticket($ticket_0));
//delete_link_user_project($user_0, $project_0);
//edit_link_user_project($user_0, $project_0, 3);
//add_link_user_project($user_0, $project_0, 5);
//print_r(get_link_user_project($user_0, $project_0));
//print_r(get_comments_for_ticket($ticket_0));
//add_comment($ticket_0, $user_0, "un commentaire");
//delete_link_ticket_category($ticket_0, $cat_0);
//add_link_ticket_category($ticket_0, $cat_0);
//print_r(get_categories_for_project($project_0));
//add_category($project_0,"test categorie");
//print_r(get_ticket($ticket_0 -1));
//add_ticket("test ticket", $project_0, $user_0, $user_0, 3, "une description en html<br/> ou en simpletext \n", "now()");
//add_project("Test Projet", $user_0, "PREF");
//echo add_user("test","test10@test.fr","mytest");
//print_r(get_user_by_email("test7@test.fr"));
?>