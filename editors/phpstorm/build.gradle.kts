// Build for the Laravel Warrant PhpStorm plugin.
//
// Uses the IntelliJ Platform Gradle Plugin 2.x. If any version below is no
// longer available when you build, bump it — IntelliJ will tell you the nearest
// valid value, and opening this folder in IntelliJ IDEA will resolve the rest.
//
// Common tasks:
//   ./gradlew runIde        launch a sandbox PhpStorm with this plugin loaded
//   ./gradlew buildPlugin   produce build/distributions/warrant-phpstorm-<ver>.zip

plugins {
    id("java")
    id("org.jetbrains.kotlin.jvm") version "2.2.20"
    id("org.jetbrains.intellij.platform") version "2.1.0"
}

group = "com.warrant"
version = "0.2.0"

repositories {
    mavenCentral()
    intellijPlatform {
        defaultRepositories()
    }
}

dependencies {
    intellijPlatform {
        // The IDE we compile and run against. PhpStorm so that `runIde` gives
        // us real PHP heredocs to test injection in, and so the bundled PHP
        // plugin API is available to compile the injector.
        phpstorm("2024.2.4")

        // We reference PHP PSI (StringLiteralExpression) in the injector.
        bundledPlugin("com.jetbrains.php")

        pluginVerifier()
        zipSigner()
    }
}

intellijPlatform {
    // We have no GUI forms or @NotNull bytecode instrumentation to do, and the
    // instrumentation step needs an extra Java-compiler dependency — skip it.
    instrumentCode = false

    // No custom settings pages, so skip the (slow) searchable-options indexing.
    buildSearchableOptions = false

    pluginConfiguration {
        ideaVersion {
            // Oldest supported build (2024.2 == 242). Open upper bound so the
            // plugin installs in current and future PhpStorm versions (instead of
            // the plugin's default 242.* cap).
            sinceBuild = "242"
            untilBuild = provider { null }
        }
    }
}

// No Java toolchain is configured, so Gradle compiles with whatever JDK runs the
// build (IntelliJ's bundled JBR when launched from the IDE). We only pin the
// *bytecode* level to 21 so the plugin loads in PhpStorm, which runs on JBR 21.
java {
    sourceCompatibility = JavaVersion.VERSION_21
    targetCompatibility = JavaVersion.VERSION_21
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_21
    }
}
