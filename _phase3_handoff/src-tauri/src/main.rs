// Tauri shell entry for ReliCheck MM Studio.
// Phase 2: spawns a Python sidecar on startup and exposes `engine_call`.
// Phase 3: native file dialog + OS-level drag-drop forwarding so the
// webview can ingest CSV/XLSX/SAV/DTA/JSON.

// Prevents additional console window on Windows in release. Does not affect macOS.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

mod python_sidecar;

use python_sidecar::{engine_call, SidecarState};
use tauri::{DragDropEvent, Emitter, Manager, WindowEvent};

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_dialog::init())
        .setup(|app| {
            // Spawn the Python sidecar once at app startup and stash it in
            // managed state. If this fails, surface the error in the app
            // logs but let the window still open so the user sees a real
            // UI rather than a silent crash.
            match SidecarState::new(&app.handle()) {
                Ok(state) => {
                    app.manage(state);
                    eprintln!("[mm-studio] python sidecar ready");
                }
                Err(e) => {
                    eprintln!("[mm-studio] failed to start python sidecar: {}", e);
                }
            }
            Ok(())
        })
        .on_window_event(|window, event| {
            // OS-level drag-drop forwarding. macOS delivers file paths
            // when the user drops something onto the window; we re-emit
            // them as a `files-dropped` event the webview listens for.
            // The three DragDropEvent variants we care about:
            //   Enter   — files entered the window's drop region
            //   Drop    — files were released; emit the paths
            //   Leave   — drag exited without dropping
            if let WindowEvent::DragDrop(drop) = event {
                match drop {
                    DragDropEvent::Drop { paths, .. } => {
                        let paths_str: Vec<String> = paths
                            .iter()
                            .map(|p| p.to_string_lossy().into_owned())
                            .collect();
                        let _ = window.emit("files-dropped", paths_str);
                    }
                    DragDropEvent::Enter { .. } => {
                        let _ = window.emit("files-drag-enter", ());
                    }
                    DragDropEvent::Leave => {
                        let _ = window.emit("files-drag-leave", ());
                    }
                    _ => {}
                }
            }
        })
        .invoke_handler(tauri::generate_handler![engine_call])
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
