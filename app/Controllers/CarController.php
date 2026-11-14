<?php
namespace App\Controllers;

use App\Models\Car;
use App\Models\User;

class CarController {
    private $db;
    private $carModel;
    private $userModel;

    public function __construct($mysqli) {
        $this->db = $mysqli;
        $this->carModel = new Car($mysqli);
        $this->userModel = new User($mysqli);
    }

    public function carDetails() {
        if (!isset($_GET['id'])) {
            header("Location: " . BASE_URL . "index.php");
            exit;
        }

        $id = is_string($_GET['id']) ? trim($_GET['id']) : '';
        if ($id === '' || strlen($id) > 50) {
            header("Location: " . BASE_URL . "index.php");
            exit;
        }
        $car = $this->carModel->getById($id);

        if (!$car) {
            header("Location: " . BASE_URL . "index.php");
            exit;
        }

        $customer = isset($_SESSION['user_id'])
            ? $this->userModel->getById((int)$_SESSION['user_id'])
            : null;

        require_once __DIR__ . '/../Views/cars/details.php';
    }

    public function adminManageCars() {
        if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
            header("Location: " . BASE_URL . "login.php");
            exit;
        }

        $error = '';
        $owner_id_filter = $_GET['owner_id'] ?? null;
        $owner_name = "";

        if ($owner_id_filter) {
            $owner = $this->userModel->getById($owner_id_filter);
            if ($owner) $owner_name = $owner['name'];
        }

        // Handle Delete
        if (isset($_GET['delete'])) {
            $id = is_string($_GET['delete']) ? $_GET['delete'] : '';
            if ($id === '') {
                header("Location: " . BASE_URL . "admin/cars.php");
                exit;
            }
            $this->carModel->deleteCar($id);
            header("Location: " . BASE_URL . "admin/cars.php");
            exit;
        }

        // Handle Add/Edit
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $model = $this->postString('model');
            $brand = $this->postString('brand');
            $plate_number = strtoupper($this->postString('plate_number'));
            $year = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT);
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $desc = $this->postString('description');
            $status = $this->postString('status') ?: 'available';
            $type = $this->postString('type') ?: 'sedan';
            $car_id = $this->postString('car_id');
            $owner_id = (int)$_SESSION['user_id'];

            if ($model === '' || strlen($model) > 100 || $brand === '' || strlen($brand) > 50 ||
                $plate_number === '' || strlen($plate_number) > 50 || $year === false ||
                $year < 1900 || $year > (int)date('Y') + 1 || $price === false || $price < 0 ||
                !in_array($type, Car::TYPES, true) ||
                !in_array($status, ['available', 'rented', 'maintenance'], true)) {
                $error = "Enter a valid brand, model, plate number, year, daily rate, and status.";
            }

            if ($error === '') {
                $uploaded_images = [];
                $target_dir = __DIR__ . "/../../uploads/";

                // Handle multiselect images[] upload (Max 8 photos)
                if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                    foreach ($_FILES['images']['name'] as $key => $name) {
                        if (count($uploaded_images) >= 8) {
                            break;
                        }
                        if ($_FILES['images']['error'][$key] == 0 && !empty($name)) {
                            $filename = time() . "_" . $key . "_" . basename($name);
                            if (move_uploaded_file($_FILES['images']['tmp_name'][$key], $target_dir . $filename)) {
                                $uploaded_images[] = $filename;
                            }
                        }
                    }
                }

                // Fall back to a single image upload for legacy forms.
                if (empty($uploaded_images) && isset($_FILES['image']) && $_FILES['image']['error'] == 0 && !empty($_FILES['image']['name'])) {
                    $filename = time() . "_" . basename($_FILES['image']['name']);
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_dir . $filename)) {
                        $uploaded_images[] = $filename;
                    }
                }

                $uploaded_images = array_slice($uploaded_images, 0, 8);

                if (!empty($uploaded_images)) {
                    $image = json_encode($uploaded_images);
                } else {
                    $image = $_POST['current_image'] ?? '';
                }
            } else {
                $image = '';
            }

            if ($error === '') {
                if ($car_id !== '') {
                    $success = $this->carModel->updateCar($car_id, $model, $year, $price, $desc, $image, $status, $owner_id, $type, $brand, $plate_number);
                } else {
                    $success = $this->carModel->addCar($model, $year, $price, $desc, $image, $status, $owner_id, $type, $brand, $plate_number);
                }

                if ($success) {
                    header("Location: " . BASE_URL . "admin/cars.php");
                    exit;
                }

                $error = $this->db->errno === 1062
                    ? "That plate number is already registered."
                    : "Database Error while saving vehicle.";
            }
        }

        $cars = $this->carModel->getAll($owner_id_filter);
        $all_owners = $this->userModel->getOwners();
        $mysqli = $this->db;

        require_once __DIR__ . '/../Views/cars/admin_cars.php';
    }

    private function postString($key) {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}
