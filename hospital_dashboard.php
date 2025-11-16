<?php
//start session and require autoloader; redirect unauthenticated hospitals
session_start();
require_once 'ClassAutoLoad.php';

if (!isset($_SESSION['hospital_id'])) { header('Location: hospital_login.php'); exit(); }
$hospital_id = $_SESSION['hospital_id'];

//fetch hospital details, recent requests, and stats
$hospital = null; $requests = []; $stats=['total'=>0,'active'=>0,'units'=>0];
try { $stmt=$conn->prepare("SELECT hospital_name AS name, contact_person, email, phone, city, address, created_at FROM hospitals WHERE id=:id"); $stmt->execute([':id'=>$hospital_id]); $hospital=$stmt->fetch(PDO::FETCH_ASSOC);} catch(Exception $e){$hospital=null;}
try { $rq=$conn->prepare("SELECT id, request_type, blood_type, units_needed AS units, urgency, deadline_at, status, created_at FROM blood_requests WHERE hospital_id=:hid ORDER BY created_at DESC LIMIT 8"); $rq->execute([':hid'=>$hospital_id]); $requests=$rq->fetchAll(PDO::FETCH_ASSOC);} catch(Exception $e){$requests=[];}
try { $q=$conn->prepare("SELECT COUNT(*) FROM blood_requests WHERE hospital_id=:hid"); $q->execute([':hid'=>$hospital_id]); $stats['total']=(int)$q->fetchColumn(); } catch(Exception $e){}
try { $q=$conn->prepare("SELECT COUNT(*) FROM blood_requests WHERE hospital_id=:hid AND (status IS NULL OR status NOT IN ('closed','completed','fulfilled'))"); $q->execute([':hid'=>$hospital_id]); $stats['active']=(int)$q->fetchColumn(); } catch(Exception $e){}
try { $q=$conn->prepare("SELECT COALESCE(SUM(units_available),0) FROM blood_inventory WHERE hospital_id=:hid"); $q->execute([':hid'=>$hospital_id]); $stats['units']=(int)$q->fetchColumn(); } catch(Exception $e){}



?>