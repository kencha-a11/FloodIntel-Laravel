<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WebTestController extends Controller
{
    // Pangalan ng temporary JSON file na tagatabi ng data natin
    private $fileName = 'temporary_todos.json';

    // Helper function para kuhanin ang data mula sa file
    private function getTodosFromFile()
    {
        if (!Storage::disk('local')->exists($this->fileName)) {
            $defaultData = [
                ['id' => 1, 'title' => 'Default Todo mula sa Laravel', 'completed' => false]
            ];
            Storage::disk('local')->put($this->fileName, json_encode($defaultData));
            return $defaultData;
        }

        return json_decode(Storage::disk('local')->get($this->fileName), true);
    }

    // Helper function para mag-save sa file
    private function saveTodosToFile($todos)
    {
        Storage::disk('local')->put($this->fileName, json_encode(array_values($todos)));
    }

    // 1. GET ALL TODOS (Index)
    public function index()
    {
        $todos = $this->getTodosFromFile();
        return response()->json($todos);
    }

    // 2. CREATE NEW TODO (Store)
    public function store(Request $request)
    {
        $todos = $this->getTodosFromFile();

        $newTodo = [
            'id' => time(),
            'title' => $request->input('title', 'Walang Pamagat'),
            'completed' => false
        ];

        $todos[] = $newTodo;
        $this->saveTodosToFile($todos);

        return response()->json([
            'status' => 'success',
            'message' => 'laravel store reach',
            'mock_created_data' => $newTodo
        ]);
    }

    // 3. UPDATE / TOGGLE TODO (Update)
    public function update(Request $request, $id)
    {
        $todos = $this->getTodosFromFile();
        $updatedTodo = null;

        foreach ($todos as $key => $todo) {
            if ($todo['id'] == $id) {
                $todos[$key]['completed'] = !$todos[$key]['completed'];
                $updatedTodo = $todos[$key];
                break;
            }
        }

        $this->saveTodosToFile($todos);

        return response()->json([
            'status' => 'success',
            'message' => 'laravel update reach',
            'updated_todo' => $updatedTodo
        ]);
    }

    // 4. DELETE TODO (Destroy)
    public function destroy($id)
    {
        $todos = $this->getTodosFromFile();

        foreach ($todos as $key => $todo) {
            if ($todo['id'] == $id) {
                unset($todos[$key]);
                break;
            }
        }

        $this->saveTodosToFile($todos);

        return response()->json([
            'status' => 'success',
            'message' => 'laravel destroy reach',
            'deleted_id' => $id
        ]);
    }
}
