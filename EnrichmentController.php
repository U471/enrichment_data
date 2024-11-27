<?php
defined('BASEPATH') or exit('No direct script access allowed');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: x-auth-token,Content-Type, Content-Length, Accept-Encoding");
header("Access-Control-Allow-Methods: GET,HEAD,OPTIONS,POST,PUT");
header("Access-Control-Allow-Headers: x-auth-token,Origin, X-Requested-With, Content-Type, Accept, Authorization");
defined('BASEPATH') or exit('No direct script access allowed');

class EnrichmentController extends CI_Controller
{

    public function __construct()
    {
        parent::__construct();
    }

    // API to handle enrichment request from the frontend
    public function enrichmentApiRequest()
    {
        if ($this->input->method(true) == 'POST') {
            // $_POST = json_decode(file_get_contents("php://input"), true);
            // Get the JWT token from request headers and decode it
            $tokenData = $this->Auth->jwtDecoden($this->input->request_headers('x-auth-token'));

            // Check if the token is valid
            if ($tokenData['status'] == 'true') {
                // Get user_id from token data
                $user_id_fk = $tokenData['data']->id;

                // Get raw input data
                $input_data = json_decode($this->input->raw_input_stream, true);

                // Validate that 'Data' and 'name' exist in the input
                if (isset($input_data['Data']) && isset($input_data['name'])) {
                    // Prepare request data for saving
                    $request_data = [
                        'user_id_fk' => $user_id_fk,
                        'name' => $input_data['name'],
                        'status' => 'pending',
                        'total_data_request' => count($input_data['Data']),
                    ];

                    // Save the request data into `tblrequest_enrichment`
                    $request_id = $this->EnrichmentModel->saveEnrichmentRequest($request_data);

                    if ($request_id) {
                        // Call ReverseIPAppends with request ID and data
                        $response = $this->ReverseIPAppends($input_data['Data'], $request_id, $user_id_fk);

                        // Return success response with the ReverseIPAppends data
                        return $this->output
                            ->set_content_type('application/json')
                            ->set_status_header(200)
                            ->set_output(json_encode($response));
                    } else {
                        // If saving the request fails
                        return $this->output
                            ->set_content_type('application/json')
                            ->set_status_header(500)
                            ->set_output(json_encode([
                                'status' => 'false',
                                'message' => 'Failed to save enrichment request.'
                            ]));
                    }
                } else {
                    // If 'Data' or 'name' is missing in the input
                    return $this->output
                        ->set_content_type('application/json')
                        ->set_status_header(400)
                        ->set_output(json_encode([
                            'status' => 'false',
                            'message' => 'Invalid request: Data or name is missing.'
                        ]));
                }
            } else {
                // Return error if token validation fails
                return $this->Response->errorResponse($tokenData['status'], $tokenData['message']);
            }
        }
    }

    // Reverse IP Append
    private function ReverseIPAppends($data, $request_id, $user_id_fk)
    {
        // Divide the data into chunks of 80
        $chunks = array_chunk($data, 2);

        $all_responses = [];
        $total_order_amount = 0;
        $status = false;

        foreach ($chunks as $chunk) {
            // Call API for each chunk
            $api_response = $this->EnrichmentModel->callAppendAPI($chunk);

            if ($api_response['Status'] == 'true') {
                // echo "API call successful.";
                $status = true;

                // Check if Data exists and is an array
                if (isset($api_response['ResponseDetail']['Data']) && is_array($api_response['ResponseDetail']['Data']) && !empty($api_response['ResponseDetail']['Data'])) {
                    // Merge the data from the response
                    $all_responses = array_merge($all_responses, $api_response['ResponseDetail']['Data']);

                    // Add the OrderAmount to the total order amount
                    if (isset($api_response['ResponseDetail']['OrderAmount'])) {
                        $order_amount = str_replace('$', '', $api_response['ResponseDetail']['OrderAmount']); // Remove the dollar sign
                        $total_order_amount += (float) $order_amount; // Convert to float and add to total
                    }
                } else {
                    continue;
                    // No data found for this chunk, log and continue to next chunk
                    // echo "No data found in the API response for this chunk.";
                }
            } else {
                continue;
                // API call failed for this chunk, log and continue to next chunk
                // echo "API call failed for this chunk.";
            }
        }

        // After processing all chunks, check if there were successful responses
        if (!empty($all_responses)) {
            // Save the response in `tblenrichment_data`
            if ($this->EnrichmentModel->saveEnrichmentData($all_responses, $request_id, $user_id_fk)) {
                // After saving all responses, call ProcessIPAddresses
                return $this->ProcessIPAddresses($all_responses, $request_id, $user_id_fk, $total_order_amount);
            }
        } else {
            // If no valid data or process failed, update request status and return failure
            $this->EnrichmentModel->updateRequestStatus($request_id, 'failed', count($all_responses));

            // Log this for debugging purposes if necessary
            // error_log("No data returned or API failed for request ID: $request_id");

            return [
                'status' => 'false',
                'message' => 'Process failed due to missing data or API failure!'
            ];
        }


        // After processing all chunks, check if there were successful responses
        if ($status === true && !empty($all_responses)) {
            // Save the response in `tblenrichment_data`
            if ($this->EnrichmentModel->saveEnrichmentData($all_responses, $request_id, $user_id_fk)) {
                // After saving all responses, call ProcessIPAddresses
                return $this->ProcessIPAddresses($all_responses, $request_id, $user_id_fk, $total_order_amount);
            }
        } else {
            // If no valid data or process failed, update request status and return failure
            $this->EnrichmentModel->updateRequestStatus($request_id, 'failed', count($all_responses));

            // You can log this for debugging purposes if necessary
            error_log("No data returned or API failed for request ID: $request_id");

            return [
                'status' => 'false',
                'message' => 'Process failed due to missing data or API failure!'
            ];
        }
    }
    // Process the IP Addresses from API response
    private function ProcessIPAddresses($response_data, $request_id, $user_id_fk, $datazapp_total_cost)
    {
        $ip_addresses = [];

        foreach ($response_data as $response) {
            if (isset($response)) {
                $ip_addresses[] = $response['IP']; // Assuming 'IP' key holds the IP address
            }
        }

        if (!empty($ip_addresses)) {

            $process_response = $this->EnrichmentModel->callProcessIPAPI($ip_addresses);
            // var_dump($process_response);
            // return $process_response;
            $total_melissa_cost = number_format(count($process_response) * 0.02, 2); // Calculate total cost
            // Save the response in `tblenrichment_data` based on IPs
            if (($this->EnrichmentModel->updateEnrichmentDataWithIP($process_response, $request_id, $user_id_fk))) {
                if ($this->EnrichmentModel->updateRequestStatus($request_id, 'done', count($response_data), $datazapp_total_cost, $total_melissa_cost)) {
                    return [
                        'status' => 'true',
                        'message' => 'Process completed successfully.'
                    ];
                }
            }
        }

        return [
            'status' => 'false',
            'message' => 'No IP addresses to process.'
        ];
    }

    // API to handle get request
    public function requestGetApi()
    {
        // Set CORS headers
        header('Access-Control-Allow-Origin: *'); // Allows requests from any origin
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS"); // Allow specific methods
        header("Access-Control-Allow-Headers: Content-Type, x-auth-token"); // Allow specific headers
        header('Access-Control-Allow-Credentials: true'); // Allow credentials if needed

        // Handle OPTIONS request (preflight request for CORS)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            // Send a 200 OK response for OPTIONS request
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(200)
                ->set_output(json_encode(array('status' => 'true', 'message' => 'CORS Preflight OK')));
        }

        if ($this->input->method(true) == 'POST') {
            $_POST = json_decode(file_get_contents("php://input"), true);
            $tokenData = $this->Auth->jwtDecoden($this->input->request_headers('x-auth-token'));

            if ($tokenData['status'] == 'true') {
                $user_id = $tokenData['data']->id;


                $data = $this->EnrichmentModel->getRequest($user_id);

                // Return the response
                if ($data) {
                    return $this->output
                        ->set_content_type('application/json')
                        ->set_status_header(200)
                        ->set_output(json_encode([
                            'status' => 'true',
                            'message' => 'Data fetched successfully.',
                            'data' => $data
                        ]));
                } else {
                    return $this->output
                        ->set_content_type('application/json')
                        ->set_status_header(404)
                        ->set_output(json_encode([
                            'status' => 'false',
                            'message' => 'No data found.'
                        ]));
                }
            } else {
                return $this->Response->errorResponse($tokenData['status'], $tokenData['message']);
            }
        } else {
            return $this->Response->errorResponse('false', 'Invalid request method');
        }
    }
    //--------------------  getRequestData ----------------
    public function getRequestData()
    {
        // Set CORS headers
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, x-auth-token");
        header('Access-Control-Allow-Credentials: true');

        // Handle OPTIONS request (preflight request for CORS)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(200)
                ->set_output(json_encode(['status' => 'true', 'message' => 'CORS Preflight OK']));
        }

        // Check if it's a POST request
        if ($this->input->method(true) == 'POST') {
            $_POST = json_decode(file_get_contents("php://input"), true);

            // Decode the JWT token
            $tokenData = $this->Auth->jwtDecoden($this->input->request_headers('x-auth-token'));

            if ($tokenData['status'] == 'true') {
                // Extract user ID from decoded token
                $user_id = $tokenData['data']->id;

                // Get the request_id from the POST data
                $request_id = $this->input->post('request_id');

                // Get the id from tblrequest_enrichment based on the request_id
                $enrichment_id = $this->EnrichmentModel->getEnrichmentId($user_id, $request_id);

                if (!$enrichment_id) {
                    return $this->Response->errorResponse('false', 'Request ID not found in tblrequest_enrichment.');
                }

                // Get the related data from tblrequest_enrichment using the enrichment_id
                $data = $this->EnrichmentModel->getRequestData($enrichment_id);

                // Return the response based on data availability
                if ($data) {
                    return $this->output
                        ->set_content_type('application/json')
                        ->set_status_header(200)
                        ->set_output(json_encode([
                            'status' => 'true',
                            'message' => 'Data fetched successfully.',
                            'data' => $data
                        ]));
                } else {
                    return $this->output
                        ->set_content_type('application/json')
                        ->set_status_header(404)
                        ->set_output(json_encode([
                            'status' => 'false',
                            'message' => 'No data found.'
                        ]));
                }
            } else {
                return $this->Response->errorResponse($tokenData['status'], $tokenData['message']);
            }
        } else {
            return $this->Response->errorResponse('false', 'Invalid request method');
        }
    }
}
