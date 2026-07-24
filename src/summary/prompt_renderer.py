from summary.input import (
    PromptSection,
    PromptDocument,
)


def _render_section(section: PromptSection) -> str:
    """Render an input section as Markdown."""

    return f"### {section.title}\n\n{section.body}"


def _render_system(document: PromptDocument) -> str:
    """Render the system section."""

    return f"## System\n\n{document.system}"


def _render_role(document: PromptDocument) -> str:
    """Render the role section."""

    return f"## Role\n\n{document.role}"


def _render_task(document: PromptDocument) -> str:
    """Render the task section."""

    return f"## Task\n\n{document.task}"


def _render_input(document: PromptDocument) -> str:
    """Render the input sections."""

    sections = "\n\n".join(
        _render_section(section)
        for section in document.sections
    )

    return f"## Input\n\n{sections}"


def render_prompt(document: PromptDocument) -> str:
    """Render a complete Markdown prompt."""

    return (
        f"# {document.title}\n\n"
        f"{_render_system(document)}\n\n"
        f"{_render_role(document)}\n\n"
        f"{_render_task(document)}\n\n"
        f"{_render_input(document)}"
    )