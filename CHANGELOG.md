# Changelog

All notable changes to `sailantis/symfony-volucer` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Version numbers follow `sailantis/volucer-worker`, and `dev-main` is aliased to
`0.4.x-dev` to match.

## [Unreleased]

## [0.4.2] - 2026-11-16

First tagged release.

### Added

- A bundle and worker runner that serve Symfony requests from a persistent volucer
  worker, rebooting the kernel per request without touching the application's code.
- `kv`, `lock`, `jobs` and `metrics` services reachable from the container, recycling on
  worker RSS, and no HTTP framing on the hot path.
- Multipart uploads, so a worker request reaches `$request->files()`.
- Requires `sailantis/volucer-worker` `^0.4.2`.
