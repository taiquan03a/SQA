<?php
class DrugController extends Controller
{
    public function process()
    {
        $AuthUser = $this->getVariable("AuthUser");

        if (!$AuthUser) {
            header("Location: " . APPURL . "/login");
            exit;
        }

        $request_method = Input::method();
        if ($request_method === 'GET') {
            $this->getById();
        }
    }

    public function getById()
    {
        $this->resp->result = 0;
        $AuthUser = $this->getVariable("AuthUser");
        $Route = $this->getVariable("Route");

        if (!$Route || !property_exists($Route, 'params') || !property_exists($Route->params, 'id')) {
            $this->resp->msg = "ID is required !";
            $this->jsonecho();
            return;
        }

        $id = $Route->params->id;

        if (!is_numeric($id)) {
            $this->resp->msg = "Invalid ID format";
            $this->jsonecho();
            return;
        }

        try {
            $query = DB::table(TABLE_PREFIX . TABLE_DRUGS)
                ->where(TABLE_PREFIX . TABLE_DRUGS . ".id", "=", $id)
                ->select("*");

            $result = $query->get();
            // Debug để xem $result trả về gì
            // var_dump($result); exit;

            if (empty($result) || count($result) == 0) {
                $this->resp->msg = "Drug not found";
                $this->jsonecho();
                return;
            }

            // Đảm bảo $result[0] tồn tại và có các thuộc tính id, name
            if (!isset($result[0]->id) || !isset($result[0]->name)) {
                $this->resp->msg = "Invalid data format from database";
                $this->jsonecho();
                return;
            }

            $data = array(
                "id" => (int)$result[0]->id,
                "name" => $result[0]->name
            );

            $this->resp->result = 1;
            $this->resp->msg = "Action successfully !";
            $this->resp->data = $data;
        } catch (Exception $ex) {
            $this->resp->msg = $ex->getMessage();
        }
        $this->jsonecho();
    }
}