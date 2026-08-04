"""Utilities for dataclass conversion."""

from __future__ import annotations

from dataclasses import fields, is_dataclass
from typing import Any, TypeVar, get_args, get_origin, get_type_hints

T = TypeVar("T")


def from_dict(
    cls: type[T],
    data: Any,
) -> T:
    """Recursively convert a dict into a dataclass."""

    if data is None:
        return None

    if not is_dataclass(cls):
        return data

    values = {}

    type_hints = get_type_hints(cls)

    for field in fields(cls):

        value = data[field.name]
        field_type = type_hints[field.name]

        origin = get_origin(field_type)

        # list[T]
        if origin is list:

            item_type = get_args(field_type)[0]

            values[field.name] = [
                from_dict(item_type, item)
                for item in value
            ]

        # nested dataclass
        elif is_dataclass(field_type):

            values[field.name] = from_dict(
                field_type,
                value,
            )

        # primitive
        else:

            values[field.name] = value

    return cls(**values)