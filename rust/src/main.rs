use std::sync::Arc;
use slims9::app::AppState;
use slims9::runtime::RuntimeConfig;
use slims9::telemetry;

fn main() {
    let runtime = match RuntimeConfig::from_env() {
        Ok(runtime) => runtime,
        Err(error) => {
            telemetry::config_error(&error);
            std::process::exit(2);
        }
    };
    let addr = runtime.app.addr.clone();
    let state = match AppState::new(runtime.app) {
        Ok(state) => Arc::new(state),
        Err(_) => {
            // Do not log a Config debug dump, hashes or session-path internals.
            telemetry::lifecycle("application_startup_refused");
            std::process::exit(2);
        }
    };
    telemetry::lifecycle("server_starting");
    if slims9::http::serve_with_settings(&addr, state.handler(), runtime.server).is_err() {
        telemetry::lifecycle("server_failed");
        std::process::exit(1);
    }
    telemetry::lifecycle("server_stopped");
}
