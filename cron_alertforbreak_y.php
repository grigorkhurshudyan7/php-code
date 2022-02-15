<?php
include 'config.php';

$file = 'filetracking.txt';
$fp = fopen($file, 'a');//opens file in append mode
$dateDetails = "";
// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

//$get_drivers = "SELECT * FROM `working_hour_logs` WHERE `end_datetime` LIKE '%0000-00-00 00:00:00%'";
$get_drivers = "SELECT * FROM `working_hour_logs` WHERE `end_datetime`= '0000-00-00 00:00:00' and `user_id` not in (112, 159)";
$result = $conn->query($get_drivers);
// print_r($result);

fwrite($fp, "\r\nStart Time : " . date("H:i:s"));
//file_put_contents($file, $dateDetails);
if ($result->num_rows > 0) {
    // output data of each row
    while ($row = $result->fetch_assoc()) {

        $check_workingdairy = "SELECT * FROM `work_dairy` WHERE `id`=" . $row['is_work_dairy'] . " ORDER BY id DESC limit 1";
        $check_working_dairy = $conn->query($check_workingdairy);
        date_default_timezone_set($row['start_timezone']);
        $cwd = [];
        if ($check_working_dairy->num_rows > 0) {
            while ($row1 = $check_working_dairy->fetch_assoc()) {
                $cwd[] = $row1;
            }
            $from_time_f = date('Y-m-d H:i');
            $to_time_f = date('Y-m-d H:i', strtotime($row['start_datetime']));
            $to_time = strtotime($from_time_f);
            $from_time = strtotime($to_time_f);
            $minutes = round(abs($to_time - $from_time) / 60, 2);
            $minutes += $cwd[0]['mintues'];
            $hours = floor($minutes / 60);

            //code for sending notification once for each color--------
            $send_notification = 0;
            $red_alert_sent = $cwd[0]['red_alert_sent'];
            $amber_alert_sent = $cwd[0]['amber_alert_sent'];
            $yellow_alert_sent = $cwd[0]['yellow_alert_sent'];
            $current_time_window_for_color = $cwd[0]['current_time_window_for_color'];
            //code for sending notification once for each color--------

            //NON WA DRIVERS
            $appuser = "SELECT * FROM `appusers` WHERE `id`=" . $row['user_id'];
            $get_appuser = $conn->query($appuser);
            $appusersdata = [];
            if ($get_appuser->num_rows > 0) {
                // output data of each row
                while ($rowx = $get_appuser->fetch_assoc()) {
                    $appusersdata[] = $rowx;
                }
            }
            if ($appusersdata[0]['is_wa_driver'] == 0) {

                $status = 4;
                if ($minutes >= 540) {
                    if ($current_time_window_for_color == '2') {
                        $update_colorstatus = "UPDATE `work_dairy` SET `red_alert_sent`='0',`amber_alert_sent`='0',`yellow_alert_sent`='0',`current_time_window_for_color`='3' where `id`=" . $row['is_work_dairy'];
                        $update_color_status = $conn->query($update_colorstatus);
                        $red_alert_sent = '0';
                        $amber_alert_sent = '0';
                        $yellow_alert_sent = '0';
                    }
                    // if($cwd[0]['l1_status'] == 1 && $cwd[0]['l2_status'] == 1)
                    // {
                    //--------
                    $driver_breakhours = "SELECT id, diff_in_mins FROM `break_hours` WHERE `user_id`= " . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '15' and deleted_at is null UNION SELECT id, diff_in_mins FROM `rest_period` WHERE `user_id`=" . $row['user_id'] . " and `start_datetime` = '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '15' and deleted_at is null";
                    $driver_break_hours = $conn->query($driver_breakhours);
                    $break_count = 0;
                    //$break_count = $driver_break_hours->num_rows;

                    // start ------
                    if ($driver_break_hours->num_rows > 0) {
                        while ($break_row = $driver_break_hours->fetch_assoc()) {
                            $break_row_obj = [];
                            $break_row_obj[] = $break_row;
                            $break_mins = $break_row_obj[0]['diff_in_mins'];
                            if ($break_mins >= 60)
                                $break_count += 4;
                            else if ($break_mins >= 45)
                                $break_count += 3;
                            else if ($break_mins >= 30)
                                $break_count += 2;
                            else if ($break_mins >= 15)
                                $break_count += 1;
                            else {
                            }
                        }
                    }
                    // end ------

                    $sql = "SELECT * FROM `break_hours` WHERE user_id = " . $row['user_id'] . " AND end_datetime = '0000-00-00 00:00:00' and deleted_at is null";
                    $driver_running_break = $conn->query($sql);
                    $running_row_obj = [];

                    if ($driver_running_break->num_rows > 0) {
                        while ($running_row = $driver_running_break->fetch_assoc()) {
                            $running_row_obj[] = $running_row;
                        }
                        $to_time = strtotime(date('Y-m-d H:i'));
                        $from_time = strtotime(date('Y-m-d H:i', strtotime($running_row_obj[0]['start_datetime'])));
                        $running_break_mins = round(abs($to_time - $from_time) / 60, 2);
                        if ($running_break_mins >= 60)
                            $break_count += 4;
                        else if ($running_break_mins >= 45)
                            $break_count += 3;
                        else if ($running_break_mins >= 30)
                            $break_count += 2;
                        else if ($running_break_mins >= 15)
                            $break_count += 1;
                        else {
                        }
                    }
                    //--------

                    if ($minutes >= 585 && $break_count <= 3 && $cwd[0]['l3_status'] != 1) {
                        $color = 'red';
                        $status = 1;

                        if ($red_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','9 hr 45 mins - Red alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else if ($minutes >= 570 && $break_count <= 3 && $cwd[0]['l3_status'] != 1) {
                        $color = 'amber';
                        $status = 2;

                        if ($amber_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','9 hr 30 mins - Orange alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else if ($minutes >= 540 && $break_count <= 3 && $cwd[0]['l3_status'] != 1) {
                        $color = 'yellow';
                        $status = 3;

                        if ($yellow_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','9 hr - Purple alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else {
                        $color = 'black';
                        $status = 4;
                    }
                    //}
                } else if ($minutes >= 360) {
                    if ($current_time_window_for_color == '1') {
                        $update_colorstatus = "UPDATE `work_dairy` SET `red_alert_sent`='0',`amber_alert_sent`='0',`yellow_alert_sent`='0',`current_time_window_for_color`='2' where `id`=" . $row['is_work_dairy'];
                        $update_color_status = $conn->query($update_colorstatus);
                        $red_alert_sent = '0';
                        $amber_alert_sent = '0';
                        $yellow_alert_sent = '0';
                    }
                    // if($cwd[0]['l1_status'] == 1)
                    // {
                    //--------
                    $driver_breakhours = "SELECT id, diff_in_mins FROM `break_hours` WHERE `user_id`= " . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '15' and deleted_at is null UNION SELECT id, diff_in_mins FROM `rest_period` WHERE `user_id`=" . $row['user_id'] . " and `start_datetime` = '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '15' and deleted_at is null";
                    $driver_break_hours = $conn->query($driver_breakhours);
                    $break_count = 0;
                    //$break_count = $driver_break_hours->num_rows;

                    // start ------
                    if ($driver_break_hours->num_rows > 0) {
                        while ($break_row = $driver_break_hours->fetch_assoc()) {
                            $break_row_obj = [];
                            $break_row_obj[] = $break_row;
                            $break_mins = $break_row_obj[0]['diff_in_mins'];
                            if ($break_mins >= 30)
                                $break_count += 2;
                            else if ($break_mins >= 15)
                                $break_count += 1;
                            else {
                            }
                        }
                    }
                    // end ------
                    $sql = "SELECT * FROM `break_hours` WHERE user_id = " . $row['user_id'] . " AND end_datetime = '0000-00-00 00:00:00' and deleted_at is null";
                    $driver_running_break = $conn->query($sql);
                    $running_row_obj = [];
                    if ($driver_running_break->num_rows > 0) {
                        while ($running_row = $driver_running_break->fetch_assoc()) {
                            $running_row_obj[] = $running_row;
                        }
                        $to_time = strtotime(date('Y-m-d H:i'));
                        $from_time = strtotime(date('Y-m-d H:i', strtotime($running_row_obj[0]['start_datetime'])));
                        $running_break_mins = round(abs($to_time - $from_time) / 60, 2);
                        if ($running_break_mins >= 30)
                            $break_count += 2;
                        else if ($running_break_mins >= 15)
                            $break_count += 1;
                        else {
                        }
                    }
                    //--------

                    if ($minutes >= 420 && $break_count <= 1 && $cwd[0]['l2_status'] != 1) {
                        $color = 'red';
                        $status = 1;

                        if ($red_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','7 hr - Red alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else if ($minutes >= 405 && $break_count <= 1 && $cwd[0]['l2_status'] != 1) {
                        $color = 'amber';
                        $status = 2;

                        if ($amber_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','6 hr 45 mins - Orange alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else if ($minutes >= 375 && $break_count <= 1 && $cwd[0]['l2_status'] != 1) {
                        $color = 'yellow';
                        $status = 3;

                        if ($yellow_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','6 hr 15 mins - Purple alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else {
                        $color = 'black';
                        $status = 4;
                    }
                    //}
                } else {
                    //--------
                    $driver_breakhours = "SELECT id FROM `break_hours` WHERE `user_id`= " . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '15' and deleted_at is null UNION SELECT id FROM `rest_period` WHERE `user_id`=" . $row['user_id'] . " and `start_datetime` = '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '15' and deleted_at is null";
                    $driver_break_hours = $conn->query($driver_breakhours);
                    $break_count = $driver_break_hours->num_rows;

                    $sql = "SELECT * FROM `break_hours` WHERE user_id = " . $row['user_id'] . " AND end_datetime = '0000-00-00 00:00:00' and deleted_at is null";
                    $driver_running_break = $conn->query($sql);
                    $running_row_obj = [];
                    if ($driver_running_break->num_rows > 0) {
                        while ($running_row = $driver_running_break->fetch_assoc()) {
                            $running_row_obj[] = $running_row;
                        }
                        $to_time = strtotime(date('Y-m-d H:i'));
                        $from_time = strtotime(date('Y-m-d H:i', strtotime($running_row_obj[0]['start_datetime'])));
                        $running_break_mins = round(abs($to_time - $from_time) / 60, 2);
                        if ($running_break_mins >= 15)
                            $break_count += 1;
                    }
                    //--------

                    if ($minutes >= 300 && $break_count <= 0 && $cwd[0]['l1_status'] != 1) {
                        $color = 'red';
                        $status = 1;

                        if ($red_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','5 hr - Red alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else if ($minutes >= 285 && $break_count <= 0 && $cwd[0]['l1_status'] != 1) {
                        $color = 'amber';
                        $status = 2;

                        if ($amber_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','4 hr 45 mins - Orange alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else if ($minutes >= 210 && $break_count <= 0 && $cwd[0]['l1_status'] != 1) {
                        $color = 'yellow';
                        $status = 3;

                        if ($yellow_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','3 hr 30 mins - Purple alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else {
                        $color = 'black';
                        $status = 4;
                    }
                }
                //echo $minutes . '-' . $break_count . '-' . $cwd[0]['l1_status'] . '-' . $status;
                //code for sending notification once for each color--------
                if ($status == 1 && $red_alert_sent != '1') {
                    $send_notification = 1;
                } else if ($status == 2 && $amber_alert_sent != '1') {
                    $send_notification = 1;
                } else if ($status == 3 && $yellow_alert_sent != '1') {
                    $send_notification = 1;
                } else {
                    if ($status == 4) {
                        //reset all color status to 0
                        $update_colorstatus = "UPDATE `work_dairy` SET `red_alert_sent`='0',`amber_alert_sent`='0',`yellow_alert_sent`='0' where `id`=" . $row['is_work_dairy'];
                        $update_color_status = $conn->query($update_colorstatus);
                    }
                }
                //code for sending notification once for each color--------

                if ($status != 4 && $send_notification == 1) {
                    //Send Notifiction code
                    $user_token = "SELECT `device_type`, `token`, `unread_badge` from user_token where user_id =" . $row['user_id'] . " and deleted_at is null order by id desc limit 1";
                    $user_tokens = $conn->query($user_token);

                    if ($user_tokens->num_rows > 0) {
                        // output data of each row
                        while ($ut = $user_tokens->fetch_assoc()) {
                            $token = $ut['token'];
                            $os = $ut['device_type'];
                            $badge = $ut['unread_badge'] + 1;

                            $body['aps'] = array(
                                'alert' => 'Working Good',
                                'content-available' => 1,
                                'badge' => $badge,
                                'sound' => 'default',
                            );

                            $body['data'] = array(
                                'title' => 'Alert',
                                'body' => 'Start thinking about impending required break',
                                'status' => $status,
                            );

                            $payload = json_encode($body);
                            //file_put_contents($file, date("H:i:s"));
                            fwrite($fp, "\r\nPayload" . $payload);
                            $response = sendNotifiction($payload, $token, $os);
                            //file_put_contents($file, date("H:i:s"));
                        }

                        //code for sending notification once for each color--------
                        //update respective color flag to 1
                        if ($status == 1) {

                            $updatewd = "UPDATE `work_dairy` SET `red_alert_sent`='1' where `id`=" . $row['is_work_dairy'];
                            $update_wd = $conn->query($updatewd);
                        }
                        if ($status == 2) {
                            $updatewd = "UPDATE `work_dairy` SET `amber_alert_sent`='1' where `id`=" . $row['is_work_dairy'];
                            $update_wd = $conn->query($updatewd);
                        }
                        if ($status == 3) {
                            $updatewd = "UPDATE `work_dairy` SET `yellow_alert_sent`='1' where `id`=" . $row['is_work_dairy'];
                            $update_wd = $conn->query($updatewd);
                        }
                        //code for sending notification once for each color--------
                    }
                }
            }
            if ($appusersdata[0]['is_wa_driver'] == 1) {
                $status = 4;
                if ($minutes >= 500) {
                    if ($current_time_window_for_color == '1') {
                        $update_colorstatus = "UPDATE `work_dairy` SET `red_alert_sent`='0',`amber_alert_sent`='0',`yellow_alert_sent`='0',`current_time_window_for_color`='2' where `id`=" . $row['is_work_dairy'];
                        $update_color_status = $conn->query($update_colorstatus);
                        $red_alert_sent = '0';
                        $amber_alert_sent = '0';
                        $yellow_alert_sent = '0';
                    }
                    //--------
                    $driver_breakhours1 = "SELECT id FROM `break_hours` WHERE `user_id`= " . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and (`diff_in_mins` >= '10' and `diff_in_mins` < '20') and deleted_at is null UNION SELECT id FROM `rest_period` WHERE `user_id`=" . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and (`diff_in_mins` >= '10' and `diff_in_mins` < '20') and deleted_at is null";
                    $driver_breakhours2 = "SELECT id FROM `break_hours` WHERE `user_id`= " . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and (`diff_in_mins` >= '20' and `diff_in_mins` < '30') and deleted_at is null UNION SELECT id FROM `rest_period` WHERE `user_id`=" . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and (`diff_in_mins` >= '20' and `diff_in_mins` < '30') and deleted_at is null";
                    $driver_breakhours3 = "SELECT id FROM `break_hours` WHERE `user_id`= " . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and (`diff_in_mins` >= '30' and `diff_in_mins` < '40') and deleted_at is null UNION SELECT id FROM `rest_period` WHERE `user_id`=" . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and (`diff_in_mins` >= '30' and `diff_in_mins` < '40') and deleted_at is null";
                    $driver_breakhours4 = "SELECT id FROM `break_hours` WHERE `user_id`= " . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '40' and deleted_at is null UNION SELECT id FROM `rest_period` WHERE `user_id`=" . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '20' and deleted_at is null";

                    $driver_break_hours1 = $conn->query($driver_breakhours1);
                    $driver_break_hours2 = $conn->query($driver_breakhours2);
                    $driver_break_hours3 = $conn->query($driver_breakhours3);
                    $driver_break_hours4 = $conn->query($driver_breakhours4);

                    $break_10_cnt = 0;
                    $break_taken = 0;
                    if ($driver_break_hours1->num_rows > 0) {
                        $break_10_cnt = $driver_break_hours1->num_rows;
                    }
                    if ($driver_break_hours2->num_rows > 0) {
                        $break_10_cnt += $driver_break_hours2->num_rows * 2;
                    }
                    if ($driver_break_hours3->num_rows > 0) {
                        $break_10_cnt += $driver_break_hours2->num_rows * 3;
                    }
                    if ($driver_break_hours4->num_rows > 0) {
                        $break_10_cnt += $driver_break_hours2->num_rows * 4;
                    }
                    if ($break_10_cnt >= 4) {
                        $break_taken = 1;
                    }

                    if ($break_taken == 0) {
                        $sql = "SELECT * FROM `break_hours` WHERE user_id = " . $row['user_id'] . " AND end_datetime = '0000-00-00 00:00:00' and deleted_at is null";
                        $driver_running_break = $conn->query($sql);
                        $running_row_obj = [];
                        if ($driver_running_break->num_rows > 0) {
                            while ($running_row = $driver_running_break->fetch_assoc()) {
                                $running_row_obj[] = $running_row;
                            }
                            $to_time = strtotime(date('Y-m-d H:i'));
                            $from_time = strtotime(date('Y-m-d H:i', strtotime($running_row_obj[0]['start_datetime'])));
                            $running_break_mins = round(abs($to_time - $from_time) / 60, 2);
                            if ($running_break_mins >= 40)
                                $break_10_cnt += 4;
                            else if ($running_break_mins >= 30)
                                $break_10_cnt += 3;
                            else if ($running_break_mins >= 20)
                                $break_10_cnt += 2;
                            else if ($running_break_mins >= 10)
                                $break_10_cnt += 1;
                            else {
                            }

                            if ($break_taken == 0 && $break_10_cnt >= 4)
                                $break_taken = 1;
                        }
                    }
                    //--------
                    if ($minutes >= 585 && $break_taken == 0) {
                        $color = 'red';
                        $status = 1;

                        if ($red_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','9 hrs 45 mins - Red alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else if ($minutes >= 570 && $break_taken == 0) {
                        $color = 'amber';
                        $status = 2;

                        if ($amber_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','9 hrs 30 mins - Orange alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else if ($minutes >= 540 && $break_taken == 0) {
                        $color = 'yellow';
                        $status = 3;

                        if ($yellow_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','9 hrs - Purple alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else {
                        $color = 'black';
                        $status = 4;
                    }
                } else if ($minutes >= 210) {
                    //--------
                    $driver_breakhours1 = "SELECT id FROM `break_hours` WHERE `user_id`= " . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '10' and deleted_at is null UNION SELECT id FROM `rest_period` WHERE `user_id`=" . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '10' and deleted_at is null";
                    $driver_breakhours2 = "SELECT id FROM `break_hours` WHERE `user_id`= " . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '20' and deleted_at is null UNION SELECT id FROM `rest_period` WHERE `user_id`=" . $row['user_id'] . " and `start_datetime` > '" . $cwd[0]['created_at'] . "' and `diff_in_mins` >= '20' and deleted_at is null";

                    $driver_break_hours1 = $conn->query($driver_breakhours1);
                    $driver_break_hours2 = $conn->query($driver_breakhours2);

                    $break_10_cnt = 0;
                    $break_taken = 0;
                    if ($driver_break_hours1->num_rows >= 2 || $driver_break_hours2->num_rows >= 1) {
                        $break_taken = 1;
                    } else {
                        $break_10_cnt = $driver_break_hours1->num_rows;
                    }

                    if ($break_taken == 0) {
                        $sql = "SELECT * FROM `break_hours` WHERE user_id = " . $row['user_id'] . " AND end_datetime = '0000-00-00 00:00:00' and deleted_at is null";
                        $driver_running_break = $conn->query($sql);
                        $running_row_obj = [];
                        if ($driver_running_break->num_rows > 0) {
                            while ($running_row = $driver_running_break->fetch_assoc()) {
                                $running_row_obj[] = $running_row;
                            }
                            $to_time = strtotime(date('Y-m-d H:i'));
                            $from_time = strtotime(date('Y-m-d H:i', strtotime($running_row_obj[0]['start_datetime'])));
                            $running_break_mins = round(abs($to_time - $from_time) / 60, 2);
                            if ($running_break_mins >= 20)
                                $break_taken = 1;
                            else if ($running_break_mins >= 10)
                                $break_10_cnt += 1;
                            else {
                            }

                            if ($break_taken == 0 && $break_10_cnt >= 2)
                                $break_taken = 1;
                        }
                    }
                    //--------

                    if ($minutes >= 300 && $break_taken == 0 && $cwd[0]['l1_status'] != 1) {
                        $color = 'red';
                        $status = 1;

                        if ($red_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','5 hr - Red alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else if ($minutes >= 285 && $break_taken == 0 && $cwd[0]['l1_status'] != 1) {
                        $color = 'amber';
                        $status = 2;

                        if ($amber_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','4 hr 45 mins - Orange alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else if ($minutes >= 210 && $break_taken == 0 && $cwd[0]['l1_status'] != 1) {
                        $color = 'yellow';
                        $status = 3;

                        if ($yellow_alert_sent != '1') {
                            $sql = "insert into alert_logs (`user_id`, `action`, `log`, `created_at`) values ('" . $row['user_id'] . "','Alert','3 hr 30 mins - Purple alert','" . date('Y-m-d H:i') . "')";
                            $conn->query($sql);
                        }
                    } else {
                        $color = 'black';
                        $status = 4;
                    }
                }
                //code for sending notification once for each color--------
                if ($status == 1 && $red_alert_sent != '1') {
                    $send_notification = 1;
                } else if ($status == 2 && $amber_alert_sent != '1') {
                    $send_notification = 1;
                } else if ($status == 3 && $yellow_alert_sent != '1') {
                    $send_notification = 1;
                } else {
                    if ($status == 4) {
                        //reset all color status to 0
                        $update_colorstatus = "UPDATE `work_dairy` SET `red_alert_sent`='0',`amber_alert_sent`='0',`yellow_alert_sent`='0' where `id`=" . $row['is_work_dairy'];
                        $update_color_status = $conn->query($update_colorstatus);
                    }
                }
                //code for sending notification once for each color--------

                if ($status != 4 && $send_notification == 1) {
                    //Send Notifiction code
                    $user_token = "SELECT `device_type`, `token`, `unread_badge` from user_token where user_id =" . $row['user_id'] . " and deleted_at is null order by id desc limit 1";
                    $user_tokens = $conn->query($user_token);

                    if ($user_tokens->num_rows > 0) {
                        // output data of each row
                        while ($ut = $user_tokens->fetch_assoc()) {
                            $token = $ut['token'];
                            $os = $ut['device_type'];
                            $badge = $ut['unread_badge'] + 1;

                            $body['aps'] = array(
                                'alert' => 'Working Good',
                                'content-available' => 1,
                                'badge' => $badge,
                                'sound' => 'default',
                            );

                            $body['data'] = array(
                                'title' => 'Alert',
                                'body' => 'Start thinking about impending required break',
                                'status' => $status,
                            );

                            $payload = json_encode($body);
                            //file_put_contents($file, date("H:i:s"));
                            fwrite($fp, "\r\nPayload" . $payload);
                            $response = sendNotifiction($payload, $token, $os);
                            //file_put_contents($file, date("H:i:s"));
                        }

                        //code for sending notification once for each color--------
                        //update respective color flag to 1
                        if ($status == 1) {
                            $updatewd = "UPDATE `work_dairy` SET `red_alert_sent`='1' where `id`=" . $row['is_work_dairy'];
                            $update_wd = $conn->query($updatewd);
                        }
                        if ($status == 2) {
                            $updatewd = "UPDATE `work_dairy` SET `amber_alert_sent`='1' where `id`=" . $row['is_work_dairy'];
                            $update_wd = $conn->query($updatewd);
                        }
                        if ($status == 3) {
                            $updatewd = "UPDATE `work_dairy` SET `yellow_alert_sent`='1' where `id`=" . $row['is_work_dairy'];
                            $update_wd = $conn->query($updatewd);
                        }
                        //code for sending notification once for each color--------
                    }
                }
            }
        }
    }

    fwrite($fp, "\r\nEnd Time : " . date("H:i:s"));
    fclose($fp);
}


//Noti function
function sendNotifiction($payload = '', $token = '', $os = '')
{
// echo "hi";exit;
    $device_token = $token;
    $payload = json_decode($payload);
    $os = $os;
    $key = "AIzaSyDloJinI32gbetHMR3irU6RhyOTL-6oyz0";

    // Set POST variables
    $url = 'https://fcm.googleapis.com/fcm/send';

//    $notification = array(
//        'body' => $payload->aps->alert,
//        'sound' => 'default',
//        //'badge' => $payload->aps->badge
//    );


    if ($os == 'ios') {
        $notification = array('title' => $payload->data->title, 'text' => $payload->data->body, 'sound' => 'default', 'badge' => $payload->aps->badge);

        $fields = array(
            'to' => $device_token,
            'notification' => $notification,
            'data' => $payload->data,
        );

    } else {

        $fields = array(
            'to' => $device_token,
            'data' => $payload->data,
        );
    }

    // echo "<pre>";print_r($fields);exit;
    $headers = array(
        'Authorization: key=' . $key,
        'Content-Type: application/json'
    );

    // Open connection
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    // Set the url, number of POST vars, POST data
    curl_setopt($ch, CURLOPT_URL, $url);

    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Disabling SSL Certificate support temporarly
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));

    // Execute post
    $result = curl_exec($ch);
    echo $result;
    if ($result === FALSE) {
        die('Curl failed: ' . curl_error($ch));
    }

    curl_close($ch);
}
