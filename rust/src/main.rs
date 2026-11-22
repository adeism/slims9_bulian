use std::sync::Arc;

use slims9::app::AppState;
use slims9::config::Config;

fn main() {
    let config = Config::from_env();
    let addr = config.addr.clone();
    let backend = match config.db {
        slims9::config::DbConfig::Memory => "memory (install/*.sql + demo circulation)",
        slims9::config::DbConfig::MySql(ref url) => {
            eprintln!(
                "slims9: MySQL {}@{}:{}/{}",
                url.user, url.host, url.port, url.database
            );
            "mysql"
        }
    };

    let state = match AppState::new(config) {
        Ok(state) => Arc::new(state),
        Err(error) => {
            eprintln!("slims9: {}", error);
            std::process::exit(1);
        }
    };

    println!("slims9-rs {} ({})", slims9::VERSION, backend);
    println!("  root      {}", state.config.root.display());
    println!("  listening http://{}", addr);
    println!("  OPAC      /  ·  /search  ·  /biblio/{{id}}  ·  /index.php");
    println!("  API       /api/  ·  /api/biblio/popular  ·  /api/loan/summary");
    println!("  health    /healthz");

    if let Err(error) = slims9::http::serve(&addr, state.handler()) {
        eprintln!("slims9: server error: {}", error);
        std::process::exit(1);
    }
}
