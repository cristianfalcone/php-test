# AGENTS.md

**Redirect**: All AI agents should read [CLAUDE.md](CLAUDE.md) for comprehensive project documentation.

This file exists for compatibility with tools that look for `AGENTS.md` by convention.

## Quick Reference

- **Full Documentation**: See [CLAUDE.md](CLAUDE.md)
- **Coding Philosophy**: See "Code Philosophy" section in CLAUDE.md
- **Development Environment**: See "Development Environment" section in CLAUDE.md
- **Architecture Patterns**: See "Architecture" section in CLAUDE.md
- **Test Runner**: See "Test Runner" section in CLAUDE.md

## Project Summary

This is a **zero-dependency PHP micro-framework** that replicates features from large frameworks with simple, elegant, robust implementations.

**Core Principles**:
- Zero dependencies (not even test runners)
- Pre-release status: **no backward compatibility constraints**
- Micro, clever, and elegant implementations
- Framework-wide consistency in code style and patterns
- Always maintain comprehensive test suites

**Development Setup**:
```bash
# Start services (uses Podman Compose, NOT Docker Compose)
podman compose up -d

# Run tests (custom test runner, NOT PHPUnit)
podman compose exec app php console test

# Run specific test suite
podman compose exec app php console test --filter=Console
```

For complete documentation, architecture details, and coding guidelines, see [CLAUDE.md](CLAUDE.md).
